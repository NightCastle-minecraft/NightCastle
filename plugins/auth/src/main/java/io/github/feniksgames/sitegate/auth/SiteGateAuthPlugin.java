package io.github.feniksgames.sitegate.auth;

import com.google.inject.Inject;
import com.velocitypowered.api.event.Subscribe;
import com.velocitypowered.api.event.connection.PreLoginEvent;
import com.velocitypowered.api.event.connection.PreLoginEvent.PreLoginComponentResult;
import com.velocitypowered.api.event.proxy.ProxyInitializeEvent;
import com.velocitypowered.api.event.proxy.ProxyShutdownEvent;
import com.velocitypowered.api.plugin.Plugin;
import com.velocitypowered.api.plugin.annotation.DataDirectory;
import com.velocitypowered.api.proxy.ProxyServer;
import io.github.feniksgames.sitegate.auth.api.SiteApi;
import io.github.feniksgames.sitegate.auth.flow.AuthListener;
import io.github.feniksgames.sitegate.auth.flow.IpLimiter;
import io.github.feniksgames.sitegate.auth.net.ConfigChannel;
import io.github.feniksgames.sitegate.auth.store.PlaytimeTracker;
import io.github.feniksgames.sitegate.auth.store.SessionCache;
import java.nio.file.Path;
import java.time.Duration;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import net.kyori.adventure.text.Component;
import net.kyori.adventure.text.format.NamedTextColor;
import org.slf4j.Logger;

@Plugin(
    id = "sitegateauth",
    name = "SiteGateAuth",
    version = SiteGateAuthPlugin.VERSION,
    description = "Вход на Minecraft-сервер через сайт: капча и подтверждение в браузере",
    url = "https://github.com/FeniksGames/NightCastle",
    authors = {"FeniksGames"})
public final class SiteGateAuthPlugin {

  static final String VERSION = "1.0.0";

  private final ProxyServer proxy;
  private final Logger log;
  private final Path dataDir;

  private ScheduledExecutorService exec;
  private AuthListener listener;
  private PlaytimeTracker playtime;

  @Inject
  public SiteGateAuthPlugin(ProxyServer proxy, Logger log, @DataDirectory Path dataDir) {
    this.proxy = proxy;
    this.log = log;
    this.dataDir = dataDir;
  }

  @Subscribe
  public void onInit(ProxyInitializeEvent event) {
    AuthConfig cfg;
    try {
      cfg = AuthConfig.load(dataDir);
    } catch (Exception e) {
      log.error("config.toml не прочитался. Вход закрыт для всех, пока не исправите.", e);
      closeAll();
      return;
    }
    if (cfg.token().isEmpty()) {
      log.error("В config.toml не задан ни site.token, ни site.token-file. Вход закрыт для всех, пока не зададите токен.");
      closeAll();
      return;
    }
    if (!ConfigChannel.supported()) {
      log.error("Эта версия Velocity не подходит: нет DialogShowPacket.nbt. Нужна 4.3.1. Вход закрыт.");
      closeAll();
      return;
    }

    exec = Executors.newSingleThreadScheduledExecutor(r -> {
      Thread t = new Thread(r, "SiteGateAuth");
      t.setDaemon(true);
      return t;
    });

    SiteApi api = new SiteApi(cfg.siteUrl(), cfg.token(), cfg.requestTimeout(), VERSION);
    SessionCache cache = new SessionCache(dataDir.resolve("sessions.json"), cfg.cacheTtl(),
        System::currentTimeMillis);
    try {
      cache.load();
    } catch (Exception e) {
      log.warn("Кеш сессий не прочитался, начинаю с пустого: {}", e.toString());
    }
    playtime = new PlaytimeTracker(api, dataDir.resolve("playtime-queue.json"),
        () -> System.currentTimeMillis() / 1000, log);
    try {
      playtime.load();
    } catch (Exception e) {
      log.warn("Очередь playtime не прочиталась: {}", e.toString());
    }
    IpLimiter limiter = new IpLimiter(cfg.loginsPerMinute(), cfg.captchaBan(),
        System::currentTimeMillis);

    listener = new AuthListener(log, cfg, api, cache, limiter, playtime, exec);
    proxy.getEventManager().register(this, listener);

    exec.scheduleWithFixedDelay(limiter::purge, 1, 1, TimeUnit.MINUTES);
    proxy.getScheduler().buildTask(this, () -> playtime.flush())
        .delay(Duration.ofSeconds(30)).repeat(Duration.ofMinutes(1)).schedule();
    proxy.getScheduler().buildTask(this, () -> api
            .heartbeat(proxy.getPlayerCount(), proxy.getConfiguration().getShowMaxPlayers())
            .exceptionally(err -> {
              log.debug("heartbeat не ушёл: {}", err.toString());
              return null;
            }))
        .delay(Duration.ofSeconds(5)).repeat(Duration.ofSeconds(30)).schedule();

    log.info("SiteGateAuth {} включён: сайт {}, в кеше {} сессий, в очереди {} записей playtime",
        VERSION, cfg.siteUrl(), cache.size(), playtime.queued());
  }

  @Subscribe
  public void onShutdown(ProxyShutdownEvent event) {
    if (playtime != null) {
      playtime.closeAll();
      try {
        playtime.flush().get(5, TimeUnit.SECONDS);
      } catch (Exception e) {
        log.warn("playtime не успел уйти на сайт, отправится при следующем запуске");
      }
    }
    if (listener != null) {
      listener.saveCache();
    }
    if (exec != null) {
      exec.shutdownNow();
    }
  }

  /** Аварийный режим: плагин не настроен, поэтому не пускаем никого (fail closed). */
  private void closeAll() {
    Component reason = Component.text("Вход временно закрыт. Попробуйте позже.", NamedTextColor.RED);
    proxy.getEventManager().register(this, PreLoginEvent.class, Short.MAX_VALUE,
        e -> e.setResult(PreLoginComponentResult.denied(reason)));
  }
}
