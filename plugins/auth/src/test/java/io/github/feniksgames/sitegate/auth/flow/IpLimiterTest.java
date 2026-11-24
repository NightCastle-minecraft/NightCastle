package io.github.feniksgames.sitegate.auth.flow;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.time.Duration;
import java.util.concurrent.atomic.AtomicLong;
import org.junit.jupiter.api.Test;

class IpLimiterTest {

  private final AtomicLong now = new AtomicLong(1_000_000);
  private final IpLimiter limiter = new IpLimiter(3, Duration.ofMinutes(5), now::get);

  @Test
  void limitsPerMinutePerIp() {
    assertTrue(limiter.tryAcquire("1.1.1.1"));
    assertTrue(limiter.tryAcquire("1.1.1.1"));
    assertTrue(limiter.tryAcquire("1.1.1.1"));
    assertFalse(limiter.tryAcquire("1.1.1.1"));
    assertTrue(limiter.tryAcquire("2.2.2.2"), "другой IP не страдает");
    now.addAndGet(60_000);
    assertTrue(limiter.tryAcquire("1.1.1.1"), "через минуту окно освобождается");
  }

  @Test
  void banExpires() {
    assertEquals(0, limiter.banLeftMillis("1.1.1.1"));
    limiter.ban("1.1.1.1");
    assertEquals(300_000, limiter.banLeftMillis("1.1.1.1"));
    now.addAndGet(299_999);
    assertEquals(1, limiter.banLeftMillis("1.1.1.1"));
    now.addAndGet(1);
    assertEquals(0, limiter.banLeftMillis("1.1.1.1"));
  }

  @Test
  void purgeDropsOldEntries() {
    limiter.tryAcquire("1.1.1.1");
    limiter.ban("3.3.3.3");
    now.addAndGet(600_000);
    limiter.purge();
    assertEquals(0, limiter.banLeftMillis("3.3.3.3"));
    assertTrue(limiter.tryAcquire("1.1.1.1"));
  }

  @Test
  void nickValidation() {
    assertTrue(Names.validNick("Steve"));
    assertTrue(Names.validNick("a_b"));
    assertTrue(Names.validNick("Abcdefghijklmnop"));
    assertFalse(Names.validNick("ab"));
    assertFalse(Names.validNick("Abcdefghijklmnopq"));
    assertFalse(Names.validNick("Стив"));
    assertFalse(Names.validNick("Steve Jobs"));
    assertFalse(Names.validNick("st;eve"));
    assertFalse(Names.validNick(null));
  }
}
