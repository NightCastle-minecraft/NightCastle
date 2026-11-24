package io.github.feniksgames.sitegate.auth.flow;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Random;
import java.util.Set;

/**
 * Капча «сколько будет x + y»: несколько кнопок с числами, одна правильная.
 * Ловит простых ботов, которые не умеют в диалоги. Ботов, написанных именно под нас, не остановит.
 */
public final class Captcha {

  private final int x;
  private final int y;
  private final List<Integer> options;

  private Captcha(int x, int y, List<Integer> options) {
    this.x = x;
    this.y = y;
    this.options = List.copyOf(options);
  }

  /** Новая капча: x и y от 2 до 49, buttons уникальных вариантов вокруг верного ответа. */
  public static Captcha generate(Random random, int buttons) {
    if (buttons < 2) {
      throw new IllegalArgumentException("buttons < 2");
    }
    int x = 2 + random.nextInt(48);
    int y = 2 + random.nextInt(48);
    int answer = x + y;
    Set<Integer> set = new LinkedHashSet<>();
    set.add(answer);
    while (set.size() < buttons) {
      int delta = 1 + random.nextInt(15);
      int candidate = random.nextBoolean() ? answer + delta : answer - delta;
      if (candidate > 0) {
        set.add(candidate);
      }
    }
    List<Integer> list = new ArrayList<>(set);
    Collections.shuffle(list, random);
    return new Captcha(x, y, list);
  }

  public int x() {
    return x;
  }

  public int y() {
    return y;
  }

  /** Числа на кнопках по порядку. */
  public List<Integer> options() {
    return options;
  }

  /** Нажата кнопка с номером index (с нуля). Неверный индекс = неверный ответ. */
  public boolean isCorrect(int index) {
    return index >= 0 && index < options.size() && options.get(index) == x + y;
  }
}
