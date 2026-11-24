package io.github.feniksgames.sitegate.auth.store;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.util.concurrent.atomic.AtomicLong;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class SessionCacheTest {

  @TempDir
  Path dir;

  private final AtomicLong now = new AtomicLong(1_000_000);

  @Test
  void validOnlyForSameIpCaseInsensitiveName() {
    SessionCache c = new SessionCache(dir.resolve("s.json"), Duration.ofDays(7), now::get);
    c.put("Steve", "1.2.3.4");
    assertTrue(c.isValid("steve", "1.2.3.4"));
    assertTrue(c.isValid("STEVE", "1.2.3.4"));
    assertFalse(c.isValid("Steve", "5.6.7.8"), "с другого IP только через сайт");
    assertFalse(c.isValid("Alex", "1.2.3.4"));
    c.remove("STEVE");
    assertFalse(c.isValid("Steve", "1.2.3.4"));
  }

  @Test
  void expires() {
    SessionCache c = new SessionCache(dir.resolve("s.json"), Duration.ofMinutes(1), now::get);
    c.put("Steve", "1.2.3.4");
    now.addAndGet(59_999);
    assertTrue(c.isValid("Steve", "1.2.3.4"));
    now.addAndGet(1);
    assertFalse(c.isValid("Steve", "1.2.3.4"));
  }

  @Test
  void survivesSaveAndLoadAndDropsExpired() throws Exception {
    Path file = dir.resolve("sub/s.json");
    SessionCache a = new SessionCache(file, Duration.ofMinutes(10), now::get);
    a.put("Steve", "1.2.3.4");
    now.addAndGet(5 * 60_000);
    a.put("Alex", "9.9.9.9");
    a.save();

    now.addAndGet(6 * 60_000); // Steve протух, Alex ещё жив
    SessionCache b = new SessionCache(file, Duration.ofMinutes(10), now::get);
    b.load();
    assertEquals(1, b.size());
    assertTrue(b.isValid("alex", "9.9.9.9"));
    assertFalse(b.isValid("steve", "1.2.3.4"));
  }

  @Test
  void brokenFileMeansEmpty() throws Exception {
    Path file = dir.resolve("s.json");
    Files.writeString(file, "{not json");
    SessionCache c = new SessionCache(file, Duration.ofDays(1), now::get);
    c.load();
    assertEquals(0, c.size());
  }
}
