package io.github.feniksgames.sitegate.auth.flow;

import com.velocitypowered.api.event.EventTask;
import com.velocitypowered.api.event.Subscribe;
import com.velocitypowered.api.event.connection.DisconnectEvent;
import com.velocitypowered.api.event.connection.PostLoginEvent;
import com.velocitypowered.api.event.connection.PreLoginEvent;
import com.velocitypowered.api.event.connection.PreLoginEvent.PreLoginComponentResult;
import com.velocitypowered.api.event.player.ServerPostConnectEvent;
import com.velocitypowered.api.event.player.ServerPreConnectEvent;
import com.velocitypowered.api.network.ProtocolVersion;
import com.velocitypowered.api.proxy.Player;
import com.velocitypowered.proxy.connection.client.ConnectedPlayer;
import io.github.feniksgames.sitegate.auth.AuthConfig;
import io.github.feniksgames.sitegate.auth.Messages;
import io.github.feniksgames.sitegate.auth.api.AuthApi;
import io.github.feniksgames.sitegate.auth.net.ConfigChannel;
import io.github.feniksgames.sitegate.auth.store.PlaytimeTracker;
import io.github.feniksgames.sitegate.auth.store.SessionCache;
import java.io.IOException;
import java.security.SecureRandom;
import java.util.Map;
import java.util.Random;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ScheduledExecutorService;
import org.slf4j.Logger;

/** События Velocity: пускать ли игрока и когда. */
public final class AuthListener {

  private final Logger log;
  private final AuthConfig cfg;
  private final AuthApi api;
  private final SessionCache cache;
  private final IpLimiter limiter;
  private final PlaytimeTracker playtime;
  private final ScheduledExecutorService exec;
  private final AuthSession.Settings settings;
  private final Random random = new SecureRandom();
  private final Set<UUID> authorized = ConcurrentHashMap.newKeySet();
  private final Map<UUID, AuthSession> sessions = new ConcurrentHashMap<>();

  public AuthListener(Logger log, AuthConfig cfg, AuthApi api, SessionCache cache, IpLimiter limiter,
      PlaytimeTracker playtime, ScheduledExecutorService exec) {
    this.log = log;
    this.cfg = cfg;
    this.api = api;
    this.cache = cache;
    this.limiter = limiter;
    this.playtime = playtime;
    this.exec = exec;
    this.settings = new AuthSession.Settings(cfg.pollInterval(), cfg.waitTimeout(),
        cfg.captchaButtons(), cfg.captchaAttempts());
  }

  /** Дешёвые проверки до логина: ник, версия клиента, лимит и бан по IP. */
  @Subscribe(priority = Short.MAX_VALUE)
  public void onPreLogin(PreLoginEvent e) {
    if (!e.getResult().isAllowed()) {
      return;
    }
    Messages msg = cfg.messages();
    String ip = Names.ip(e.getConnection().getRemoteAddress());
    if (!Names.validNick(e.getUsername())) {
      e.setResult(PreLoginComponentResult.denied(msg.get("kick-bad-name")));
      return;
    }
    if (e.getConnection().getProtocolVersion().lessThan(ProtocolVersion.MINECRAFT_1_21_6)) {
      e.setResult(PreLoginComponentResult.denied(msg.get("kick-old-client")));
      return;
    }
    long ban = limiter.banLeftMillis(ip);
    if (ban > 0) {
      e.setResult(PreLoginComponentResult.denied(
          msg.get("kick-captcha-ban", "minutes", Long.toString(Math.max(1, (ban + 59_999) / 60_000)))));
      return;
    }
    if (!limiter.tryAcquire(ip)) {
      e.setResult(PreLoginComponentResult.denied(msg.get("kick-rate-limit")));
    }
  }

  /**
   * Держит игрока в фазе конфигурации, пока он не авторизуется. Velocity ждёт этот EventTask
   * и только потом подключает игрока к SMP.
   */
  @Subscribe(priority = Short.MAX_VALUE)
  public EventTask onPostLogin(PostLoginEvent e) {
    Player player = e.getPlayer();
    if (!(player instanceof ConnectedPlayer cp)) {
      player.disconnect(cfg.messages().get("kick-closed"));
      return EventTask.resumeWhenComplete(CompletableFuture.completedFuture(null));
    }
    UUID uuid = player.getUniqueId();
    String name = player.getUsername();
    String ip = Names.ip(player.getRemoteAddress());

    AuthSession[] holder = new AuthSession[1];
    ConfigChannel channel = ConfigChannel.attach(cp, id -> holder[0].onClick(id));
    VelocityUi ui = new VelocityUi(cp, channel, cfg.messages(), () -> authorized.add(uuid));
    AuthSession session = new AuthSession(name, ip, api, cacheView(), limiter, settings, exec,
        random, ui, log);
    holder[0] = session;
    sessions.put(uuid, session);
    cp.getConnection().getChannel().closeFuture().addListener(f -> session.cancel());

    session.start();
    CompletableFuture<Boolean> done = session.result().whenComplete((ok, err) -> {
      sessions.remove(uuid, session);
      if (err != null) {
        log.error("Сбой входа {}", name, err);
      }
    });
    return EventTask.resumeWhenComplete(done);
  }

  /** Вторая линия обороны: неавторизованных не пускаем ни на один сервер. */
  @Subscribe(priority = Short.MAX_VALUE)
  public void onServerPreConnect(ServerPreConnectEvent e) {
    if (!authorized.contains(e.getPlayer().getUniqueId())) {
      e.setResult(ServerPreConnectEvent.ServerResult.denied());
    }
  }

  @Subscribe
  public void onServerPostConnect(ServerPostConnectEvent e) {
    if (e.getPreviousServer() == null && authorized.contains(e.getPlayer().getUniqueId())) {
      playtime.started(e.getPlayer().getUniqueId(), e.getPlayer().getUsername());
    }
  }

  @Subscribe(priority = Short.MIN_VALUE)
  public void onDisconnect(DisconnectEvent e) {
    UUID uuid = e.getPlayer().getUniqueId();
    AuthSession session = sessions.remove(uuid);
    if (session != null) {
      session.cancel();
    }
    authorized.remove(uuid);
    playtime.stopped(uuid);
  }

  /** Сохраняет кеш сессий на диск. */
  public void saveCache() {
    try {
      cache.save();
    } catch (IOException ex) {
      log.warn("Не удалось сохранить кеш сессий: {}", ex.toString());
    }
  }

  private AuthSession.SessionCacheView cacheView() {
    return new AuthSession.SessionCacheView() {
      @Override
      public boolean isValid(String name, String ip) {
        return cache.isValid(name, ip);
      }

      @Override
      public void put(String name, String ip) {
        cache.put(name, ip);
        saveCache();
      }

      @Override
      public void remove(String name) {
        cache.remove(name);
        saveCache();
      }
    };
  }
}
