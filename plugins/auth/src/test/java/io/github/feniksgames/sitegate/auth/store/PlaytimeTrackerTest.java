package io.github.feniksgames.sitegate.auth.store;

import static org.junit.jupiter.api.Assertions.assertEquals;

import io.github.feniksgames.sitegate.auth.api.AuthApi;
import java.io.IOException;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicLong;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.slf4j.helpers.NOPLogger;

class PlaytimeTrackerTest {

  @TempDir
  Path dir;

  private final AtomicLong now = new AtomicLong(1_000);
  private final List<List<AuthApi.PlaySession>> sent = new ArrayList<>();
  private volatile boolean siteDown;

  private final AuthApi api = new AuthApi() {
    @Override
    public CompletableFuture<CheckResult> check(String name, String ip) {
      throw new UnsupportedOperationException();
    }

    @Override
    public CompletableFuture<Ticket> createTicket(String name, String ip) {
      throw new UnsupportedOperationException();
    }

    @Override
    public CompletableFuture<TicketStatus> ticketStatus(String ticket) {
      throw new UnsupportedOperationException();
    }

    @Override
    public CompletableFuture<Void> postSessions(List<PlaySession> sessions) {
      if (siteDown) {
        return CompletableFuture.failedFuture(new IOException("down"));
      }
      sent.add(List.copyOf(sessions));
      return CompletableFuture.completedFuture(null);
    }

    @Override
    public CompletableFuture<Void> heartbeat(int online, int max) {
      return CompletableFuture.completedFuture(null);
    }
  };

  private PlaytimeTracker tracker() {
    return new PlaytimeTracker(api, dir.resolve("q.json"), now::get, NOPLogger.NOP_LOGGER);
  }

  @Test
  void sessionIsMeasuredAndSent() throws Exception {
    PlaytimeTracker t = tracker();
    UUID u = UUID.randomUUID();
    t.started(u, "Steve");
    now.addAndGet(3600);
    t.stopped(u);
    assertEquals(1, t.queued());
    t.flush().get(1, TimeUnit.SECONDS);
    assertEquals(0, t.queued());
    assertEquals(List.of(new AuthApi.PlaySession("Steve", 1_000, 4_600)), sent.get(0));
  }

  @Test
  void failedSendKeepsQueueOnDiskAcrossRestart() throws Exception {
    PlaytimeTracker t = tracker();
    UUID u = UUID.randomUUID();
    t.started(u, "Steve");
    now.addAndGet(60);
    t.stopped(u);
    siteDown = true;
    t.flush().get(1, TimeUnit.SECONDS);
    assertEquals(1, t.queued(), "сайт лежит: сессия не потерялась");

    PlaytimeTracker restarted = tracker();
    restarted.load();
    assertEquals(1, restarted.queued(), "очередь пережила рестарт прокси");
    siteDown = false;
    restarted.flush().get(1, TimeUnit.SECONDS);
    assertEquals(0, restarted.queued());
    assertEquals(1, sent.size());
  }

  @Test
  void zeroLengthAndUnknownAreIgnoredAndCloseAllFlushesOpen() throws Exception {
    PlaytimeTracker t = tracker();
    UUID a = UUID.randomUUID();
    t.started(a, "Alex");
    t.stopped(a); // 0 секунд
    t.stopped(UUID.randomUUID()); // не начинал
    assertEquals(0, t.queued());

    UUID b = UUID.randomUUID();
    t.started(b, "Bob");
    t.started(b, "Bob"); // повторный старт не сбрасывает начало
    now.addAndGet(10);
    t.closeAll();
    assertEquals(1, t.queued());
  }
}
