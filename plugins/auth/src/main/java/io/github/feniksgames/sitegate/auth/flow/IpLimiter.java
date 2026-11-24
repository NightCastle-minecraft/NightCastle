package io.github.feniksgames.sitegate.auth.flow;

import java.time.Duration;
import java.util.ArrayDeque;
import java.util.Deque;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.function.LongSupplier;

/** Лимит входов с одного IP и временный бан IP после проваленной капчи. Всё в памяти. */
public final class IpLimiter {

  private static final long WINDOW_MS = 60_000;

  private final int perMinute;
  private final long banMs;
  private final LongSupplier clock;
  private final Map<String, Deque<Long>> attempts = new ConcurrentHashMap<>();
  private final Map<String, Long> bans = new ConcurrentHashMap<>();

  public IpLimiter(int perMinute, Duration ban, LongSupplier clockMillis) {
    this.perMinute = perMinute;
    this.banMs = ban.toMillis();
    this.clock = clockMillis;
  }

  /** Засчитывает попытку входа. false = лимит на эту минуту исчерпан. */
  public boolean tryAcquire(String ip) {
    long now = clock.getAsLong();
    Deque<Long> q = attempts.computeIfAbsent(ip, k -> new ArrayDeque<>());
    synchronized (q) {
      while (!q.isEmpty() && now - q.peekFirst() >= WINDOW_MS) {
        q.pollFirst();
      }
      if (q.size() >= perMinute) {
        return false;
      }
      q.addLast(now);
      return true;
    }
  }

  public void ban(String ip) {
    bans.put(ip, clock.getAsLong() + banMs);
  }

  /** Сколько миллисекунд ещё действует бан; 0, если бана нет. */
  public long banLeftMillis(String ip) {
    Long until = bans.get(ip);
    if (until == null) {
      return 0;
    }
    long left = until - clock.getAsLong();
    if (left <= 0) {
      bans.remove(ip, until);
      return 0;
    }
    return left;
  }

  /** Чистит старые записи, чтобы карты не росли бесконечно. */
  public void purge() {
    long now = clock.getAsLong();
    bans.values().removeIf(until -> until <= now);
    attempts.entrySet().removeIf(e -> {
      Deque<Long> q = e.getValue();
      synchronized (q) {
        Long last = q.peekLast();
        return last == null || now - last >= WINDOW_MS;
      }
    });
  }
}
