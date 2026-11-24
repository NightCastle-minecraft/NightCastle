package io.github.feniksgames.sitegate.auth.flow;

import io.github.feniksgames.sitegate.auth.api.AuthApi;
import io.github.feniksgames.sitegate.auth.api.AuthApi.TicketStatus;
import io.github.feniksgames.sitegate.auth.net.Dialogs;
import java.time.Duration;
import java.util.Random;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import org.slf4j.Logger;

/**
 * Вход одного игрока, пока он стоит в фазе конфигурации.
 *
 * <pre>
 * check (сайт) ─ok──────────────────────────────────────────► approve
 *      │ need_auth                     сайт лежит + есть кеш ─► approve
 *      ▼
 *   CAPTCHA ─ошибки кончились─► бан IP + kick
 *      │ верно
 *      ▼
 *   тикет → окно «Нажмите, чтобы авторизоваться» → опрос сайта
 *      approved → approve · denied → kick · expired или таймаут → kick
 * </pre>
 *
 * Все переходы идут на одном потоке {@code exec}, поэтому синхронизация не нужна.
 * Ввод-вывод (сайт, пакеты) спрятан за {@link Ui} и {@link AuthApi}, чтобы автомат можно было
 * тестировать без Velocity.
 */
public final class AuthSession {

  /** То, что сессия делает с игроком. В проде это пакеты и kick через Velocity. */
  public interface Ui {
    void showCaptcha(Captcha captcha, int attemptsLeft, boolean wrong);

    void showLogin(String url, int minutes);

    void keepAlive();

    /** Вход разрешён: закрыть окно и пустить дальше. */
    void approved();

    /** Кик с текстом по ключу из [messages]. */
    void kick(String messageKey, String... placeholders);
  }

  public record Settings(
      Duration pollInterval, Duration waitTimeout, int captchaButtons, int captchaAttempts) {}

  enum Stage { CHECKING, CAPTCHA, WAITING, DONE }

  private static final Duration KEEPALIVE = Duration.ofSeconds(10);

  private final String name;
  private final String ip;
  private final AuthApi api;
  private final SessionCacheView cache;
  private final IpLimiter limiter;
  private final Settings settings;
  private final ScheduledExecutorService exec;
  private final Random random;
  private final Ui ui;
  private final Logger log;
  private final CompletableFuture<Boolean> result = new CompletableFuture<>();

  private Stage stage = Stage.CHECKING;
  private Captcha captcha;
  private int attemptsLeft;
  private String ticket;
  private boolean pollInFlight;
  private ScheduledFuture<?> keepAliveTask;
  private ScheduledFuture<?> pollTask;
  private ScheduledFuture<?> deadlineTask;

  /** Кусок кеша сессий, который нужен автомату. */
  public interface SessionCacheView {
    boolean isValid(String name, String ip);

    void put(String name, String ip);

    void remove(String name);
  }

  public AuthSession(String name, String ip, AuthApi api, SessionCacheView cache, IpLimiter limiter,
      Settings settings, ScheduledExecutorService exec, Random random, Ui ui, Logger log) {
    this.name = name;
    this.ip = ip;
    this.api = api;
    this.cache = cache;
    this.limiter = limiter;
    this.settings = settings;
    this.exec = exec;
    this.random = random;
    this.ui = ui;
    this.log = log;
    this.attemptsLeft = settings.captchaAttempts();
  }

  /** true = игрок авторизован; false = кикнут или ушёл сам. Завершается всегда. */
  public CompletableFuture<Boolean> result() {
    return result;
  }

  public void start() {
    exec.execute(() -> api.check(name, ip).whenCompleteAsync((res, err) -> {
      if (stage != Stage.CHECKING) {
        return;
      }
      if (err != null) {
        log.warn("Сайт не ответил на check для {}: {}", name, rootMessage(err));
        if (cache.isValid(name, ip)) {
          log.info("{} пущен по локальному кешу сессии", name);
          approve();
        } else {
          kick("kick-site-down");
        }
        return;
      }
      if (res.ok()) {
        cache.put(name, ip);
        approve();
      } else {
        cache.remove(name);
        beginCaptcha();
      }
    }, exec));
  }

  /** Клик по кнопке нашего диалога. Можно звать с любого потока. */
  public void onClick(String id) {
    exec.execute(() -> handleClick(id));
  }

  /** Игрок отключился сам. Можно звать с любого потока. */
  public void cancel() {
    exec.execute(() -> finish(false));
  }

  Stage stage() {
    return stage;
  }

  private void handleClick(String id) {
    if (stage == Stage.DONE) {
      return;
    }
    if (Dialogs.LEAVE.equals(id)) {
      kick("kick-left");
      return;
    }
    if (stage != Stage.CAPTCHA || !id.startsWith(Dialogs.CAPTCHA_PREFIX)) {
      return;
    }
    int index;
    try {
      index = Integer.parseInt(id.substring(Dialogs.CAPTCHA_PREFIX.length()));
    } catch (NumberFormatException e) {
      index = -1;
    }
    if (captcha.isCorrect(index)) {
      requestTicket();
      return;
    }
    attemptsLeft--;
    if (attemptsLeft <= 0) {
      limiter.ban(ip);
      kick("kick-captcha-ban", "minutes", minutesOf(limiterBanLeft()));
      return;
    }
    captcha = Captcha.generate(random, settings.captchaButtons());
    ui.showCaptcha(captcha, attemptsLeft, true);
  }

  private void beginCaptcha() {
    stage = Stage.CAPTCHA;
    keepAliveTask = exec.scheduleAtFixedRate(this::keepAlive,
        KEEPALIVE.toMillis(), KEEPALIVE.toMillis(), TimeUnit.MILLISECONDS);
    deadlineTask = exec.schedule(() -> {
      if (stage != Stage.DONE) {
        kick("kick-timeout");
      }
    }, settings.waitTimeout().toMillis(), TimeUnit.MILLISECONDS);
    captcha = Captcha.generate(random, settings.captchaButtons());
    ui.showCaptcha(captcha, attemptsLeft, false);
  }

  private void requestTicket() {
    stage = Stage.WAITING;
    api.createTicket(name, ip).whenCompleteAsync((t, err) -> {
      if (stage != Stage.WAITING) {
        return;
      }
      if (err != null) {
        log.warn("Сайт не выдал тикет для {}: {}", name, rootMessage(err));
        kick("kick-site-down");
        return;
      }
      ticket = t.ticket();
      int minutes = Math.max(1, Math.min(t.expiresInSeconds() / 60,
          (int) settings.waitTimeout().toMinutes()));
      ui.showLogin(t.url(), minutes);
      long every = settings.pollInterval().toMillis();
      pollTask = exec.scheduleWithFixedDelay(this::poll, every, every, TimeUnit.MILLISECONDS);
    }, exec);
  }

  private void poll() {
    if (stage != Stage.WAITING || ticket == null || pollInFlight) {
      return;
    }
    pollInFlight = true;
    api.ticketStatus(ticket).whenCompleteAsync((st, err) -> {
      pollInFlight = false;
      if (stage != Stage.WAITING) {
        return;
      }
      if (err != null) {
        // Сайт мигнул: пробуем дальше, пока не выйдет общий таймаут.
        log.debug("Опрос тикета {} не удался: {}", name, rootMessage(err));
        return;
      }
      TicketStatus.State state = st.state();
      switch (state) {
        case APPROVED -> {
          cache.put(name, ip);
          approve();
        }
        case DENIED -> kick("kick-denied");
        case EXPIRED -> kick("kick-timeout");
        case PENDING -> { }
        default -> { }
      }
    }, exec);
  }

  private void keepAlive() {
    if (stage != Stage.DONE) {
      ui.keepAlive();
    }
  }

  private void approve() {
    if (stage == Stage.DONE) {
      return;
    }
    stopTimers();
    stage = Stage.DONE;
    ui.approved();
    result.complete(true);
  }

  private void kick(String key, String... placeholders) {
    if (stage == Stage.DONE) {
      return;
    }
    stopTimers();
    stage = Stage.DONE;
    ui.kick(key, placeholders);
    result.complete(false);
  }

  private void finish(boolean ok) {
    if (stage == Stage.DONE) {
      return;
    }
    stopTimers();
    stage = Stage.DONE;
    result.complete(ok);
  }

  private void stopTimers() {
    for (ScheduledFuture<?> f : new ScheduledFuture<?>[] {keepAliveTask, pollTask, deadlineTask}) {
      if (f != null) {
        f.cancel(false);
      }
    }
  }

  private long limiterBanLeft() {
    return limiter.banLeftMillis(ip);
  }

  private static String minutesOf(long millis) {
    return Long.toString(Math.max(1, (millis + 59_999) / 60_000));
  }

  private static String rootMessage(Throwable t) {
    Throwable cur = t;
    while (cur.getCause() != null && cur.getCause() != cur) {
      cur = cur.getCause();
    }
    return cur.getClass().getSimpleName() + (cur.getMessage() != null ? ": " + cur.getMessage() : "");
  }
}
