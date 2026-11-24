package io.github.feniksgames.sitegate.shop;

import java.util.List;
import java.util.Objects;
import java.util.regex.Pattern;

/** Товар из products/<code>.yml: что выполнить при выдаче. */
public record Product(String code, boolean requireOnline, List<String> commands, String message) {

  public static final Pattern CODE = Pattern.compile("[a-z0-9_]{2,64}");

  public Product {
    Objects.requireNonNull(code, "code");
    if (!CODE.matcher(code).matches()) {
      throw new IllegalArgumentException("код товара: 2–64 символа, a-z, 0-9 и _ (" + code + ")");
    }
    commands = List.copyOf(commands);
    if (commands.isEmpty()) {
      throw new IllegalArgumentException("у товара " + code + " нет commands");
    }
    message = message == null ? "" : message;
  }
}
