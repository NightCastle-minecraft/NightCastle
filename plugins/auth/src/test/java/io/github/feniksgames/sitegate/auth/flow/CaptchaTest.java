package io.github.feniksgames.sitegate.auth.flow;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.HashSet;
import java.util.Random;
import org.junit.jupiter.api.Test;

class CaptchaTest {

  @Test
  void exactlyOneCorrectButtonAndAllUnique() {
    Random r = new Random(42);
    for (int run = 0; run < 2000; run++) {
      Captcha c = Captcha.generate(r, 6);
      assertEquals(6, c.options().size());
      assertEquals(6, new HashSet<>(c.options()).size(), "варианты не повторяются");
      int correct = 0;
      for (int i = 0; i < 6; i++) {
        assertTrue(c.options().get(i) > 0);
        if (c.isCorrect(i)) {
          correct++;
          assertEquals(c.x() + c.y(), c.options().get(i));
        }
      }
      assertEquals(1, correct);
    }
  }

  @Test
  void outOfRangeIndexIsWrong() {
    Captcha c = Captcha.generate(new Random(1), 4);
    assertFalse(c.isCorrect(-1));
    assertFalse(c.isCorrect(4));
    assertFalse(c.isCorrect(Integer.MAX_VALUE));
  }
}
