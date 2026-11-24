package io.github.feniksgames.sitegate.auth.flow;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

import io.github.feniksgames.sitegate.auth.api.AuthApi;
import io.github.feniksgames.sitegate.auth.api.AuthApi.TicketStatus.State;
import io.github.feniksgames.sitegate.auth.net.Dialogs;
import java.io.IOException;
import java.time.Duration;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Random;
import java.util.Set;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.concurrent.Executors;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;
import org.slf4j.helpers.NOPLogger;

/** Автомат входа целиком, без Velocity: фейковый сайт и фейковый экран. */
class AuthSessionTest {

  private static final String IP = "1.2.3.4";

  private final ScheduledExecutorService exec = Executors.newSingleThreadScheduledExecutor();
  private final FakeApi api = new FakeApi();
  private final FakeCache cache = new FakeCache();
  private final IpLimiter limiter = new IpLimiter(100, Duration.ofMinutes(5), System::currentTimeMillis);
  private final FakeUi ui = new FakeUi();

  @AfterEach
  void stop() {
    exec.shutdownNow();
  }

  private AuthSession session(Duration wait) {
    AuthSession.Settings s = new AuthSession.Settings(Duration.ofMillis(20), wait, 6, 3);
    return new AuthSession("Steve", IP, api, cache, limiter, s, exec, new Random(7), ui,
        NOPLogger.NOP_LOGGER);
  }

  private AuthSession session() {
    return session(Duration.ofSeconds(5));
  }

  @Test
  void liveSessionGoesStraightIn() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(true, true));
    AuthSession s = session();
    s.start();
    assertTrue(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("approved", ui.next());
    assertTrue(cache.valid.contains("steve"));
    assertNull(ui.captcha, "капчу не показывали");
  }

  @Test
  void siteDownButCachedSessionGoesIn() throws Exception {
    api.checkResult = CompletableFuture.failedFuture(new IOException("down"));
    cache.valid.add("steve");
    AuthSession s = session();
    s.start();
    assertTrue(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("approved", ui.next());
  }

  @Test
  void siteDownWithoutCacheKicks() throws Exception {
    api.checkResult = CompletableFuture.failedFuture(new IOException("down"));
    AuthSession s = session();
    s.start();
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-site-down", ui.next());
  }

  @Test
  void fullLoginThroughSite() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, false));
    api.statuses.add(State.PENDING);
    api.statuses.add(State.PENDING);
    api.statuses.add(State.APPROVED);
    cache.valid.add("steve"); // старая запись: need_auth обязан её стереть
    AuthSession s = session();
    s.start();

    assertEquals("captcha", ui.next());
    assertFalse(cache.valid.contains("steve"));
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.correctIndex());
    assertEquals("login:https://site/auth/1abcdefghijklmnopqrstuvwxyz", ui.next());
    assertTrue(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("approved", ui.next());
    assertTrue(cache.valid.contains("steve"));
    assertEquals(3, api.polls);
  }

  @Test
  void wrongAnswersThenBan() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    AuthSession s = session();
    s.start();
    assertEquals("captcha", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.wrongIndex());
    assertEquals("captcha-wrong:2", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.wrongIndex());
    assertEquals("captcha-wrong:1", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + "garbage");
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-captcha-ban", ui.next());
    assertTrue(limiter.banLeftMillis(IP) > 0);
  }

  @Test
  void deniedOnSiteKicks() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    api.statuses.add(State.DENIED);
    AuthSession s = session();
    s.start();
    assertEquals("captcha", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.correctIndex());
    assertNotNull(ui.next());
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-denied", ui.next());
  }

  @Test
  void leaveButtonKicks() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    AuthSession s = session();
    s.start();
    assertEquals("captcha", ui.next());
    s.onClick(Dialogs.LEAVE);
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-left", ui.next());
  }

  @Test
  void waitingTooLongKicks() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    AuthSession s = session(Duration.ofMillis(300));
    s.start();
    assertEquals("captcha", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.correctIndex());
    assertNotNull(ui.next());
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-timeout", ui.next());
  }

  @Test
  void disconnectCancelsWithoutKick() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    AuthSession s = session();
    s.start();
    assertEquals("captcha", ui.next());
    s.cancel();
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.correctIndex()); // поздний клик после выхода игнорируется
    Thread.sleep(100);
    assertNull(ui.events.poll(), "после отмены ничего не происходит");
    assertEquals(0, api.tickets);
  }

  @Test
  void ticketFailureKicks() throws Exception {
    api.checkResult = CompletableFuture.completedFuture(new AuthApi.CheckResult(false, true));
    api.ticketFails = true;
    AuthSession s = session();
    s.start();
    assertEquals("captcha", ui.next());
    s.onClick(Dialogs.CAPTCHA_PREFIX + ui.correctIndex());
    assertFalse(s.result().get(2, TimeUnit.SECONDS));
    assertEquals("kick:kick-site-down", ui.next());
  }

  static final class FakeApi implements AuthApi {
    volatile CompletableFuture<CheckResult> checkResult;
    final ConcurrentLinkedQueue<State> statuses = new ConcurrentLinkedQueue<>();
    volatile int polls;
    volatile int tickets;
    volatile boolean ticketFails;

    @Override
    public CompletableFuture<CheckResult> check(String name, String ip) {
      return checkResult;
    }

    @Override
    public CompletableFuture<Ticket> createTicket(String name, String ip) {
      tickets++;
      if (ticketFails) {
        return CompletableFuture.failedFuture(new IOException("500"));
      }
      return CompletableFuture.completedFuture(
          new Ticket("1abcdefghijklmnopqrstuvwxyz", "https://site/auth/1abcdefghijklmnopqrstuvwxyz", 600));
    }

    @Override
    public CompletableFuture<TicketStatus> ticketStatus(String ticket) {
      polls++;
      State st = statuses.poll();
      return CompletableFuture.completedFuture(new TicketStatus(st == null ? State.PENDING : st));
    }

    @Override
    public CompletableFuture<Void> postSessions(List<PlaySession> sessions) {
      return CompletableFuture.completedFuture(null);
    }

    @Override
    public CompletableFuture<Void> heartbeat(int online, int max) {
      return CompletableFuture.completedFuture(null);
    }
  }

  static final class FakeCache implements AuthSession.SessionCacheView {
    final Set<String> valid = java.util.Collections.synchronizedSet(new HashSet<>());

    @Override
    public boolean isValid(String name, String ip) {
      return valid.contains(name.toLowerCase());
    }

    @Override
    public void put(String name, String ip) {
      valid.add(name.toLowerCase());
    }

    @Override
    public void remove(String name) {
      valid.remove(name.toLowerCase());
    }
  }

  static final class FakeUi implements AuthSession.Ui {
    final BlockingQueue<String> events = new LinkedBlockingQueue<>();
    volatile Captcha captcha;

    String next() throws InterruptedException {
      String e = events.poll(2, TimeUnit.SECONDS);
      assertNotNull(e, "событие не пришло");
      return e;
    }

    int correctIndex() {
      for (int i = 0; i < captcha.options().size(); i++) {
        if (captcha.isCorrect(i)) {
          return i;
        }
      }
      throw new AssertionError();
    }

    int wrongIndex() {
      List<Integer> idx = new ArrayList<>();
      for (int i = 0; i < captcha.options().size(); i++) {
        if (!captcha.isCorrect(i)) {
          idx.add(i);
        }
      }
      return idx.get(0);
    }

    @Override
    public void showCaptcha(Captcha c, int attemptsLeft, boolean wrong) {
      captcha = c;
      events.add(wrong ? "captcha-wrong:" + attemptsLeft : "captcha");
    }

    @Override
    public void showLogin(String url, int minutes) {
      events.add("login:" + url);
    }

    @Override
    public void keepAlive() {
    }

    @Override
    public void approved() {
      events.add("approved");
    }

    @Override
    public void kick(String messageKey, String... placeholders) {
      events.add("kick:" + messageKey);
    }
  }
}
