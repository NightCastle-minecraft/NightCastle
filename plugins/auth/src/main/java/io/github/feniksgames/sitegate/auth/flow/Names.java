package io.github.feniksgames.sitegate.auth.flow;

import java.net.InetSocketAddress;
import java.util.regex.Pattern;

/** Проверка ника и IP в виде строки. */
public final class Names {

  private static final Pattern NICK = Pattern.compile("^[A-Za-z0-9_]{3,16}$");

  private Names() {
  }

  public static boolean validNick(String name) {
    return name != null && NICK.matcher(name).matches();
  }

  /** IP без порта; IPv6 в стандартной форме Java. */
  public static String ip(InetSocketAddress address) {
    if (address.getAddress() != null) {
      return address.getAddress().getHostAddress();
    }
    return address.getHostString();
  }
}
