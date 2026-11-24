package io.github.feniksgames.sitegate.shop;

import java.io.IOException;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;
import java.util.logging.Logger;
import java.util.regex.Pattern;

/**
 * Выдача заказов. Без Bukkit: сервер виден через {@link Server}, поэтому логику гоняют тесты.
 * Вызывается в главном потоке сервера: команды Bukkit можно выполнять только там.
 */
public final class Delivery {

  private static final Pattern NICK = Pattern.compile("[A-Za-z0-9_]{3,16}");

  /** Что нужно от игрового сервера. */
  public interface Server {
    boolean isOnline(String name);

    String uuidOf(String name);

    /** Выполнить команду от консоли. false = команда не найдена или упала. */
    boolean dispatch(String command);

    /** Сообщение игроку, если он онлайн (MiniMessage). */
    void message(String name, String miniMessage, String title);
  }

  private final Server server;
  private final Journal journal;
  private final Logger log;
  private final Set<String> warnedUnknown = new HashSet<>();
  private volatile Map<String, Product> products;
  private final String defaultMessage;

  public Delivery(Server server, Journal journal, Map<String, Product> products, String defaultMessage, Logger log) {
    this.server = server;
    this.journal = journal;
    this.products = Map.copyOf(products);
    this.defaultMessage = defaultMessage;
    this.log = log;
  }

  public void setProducts(Map<String, Product> products) {
    this.products = Map.copyOf(products);
    warnedUnknown.clear();
  }

  public Map<String, Product> products() {
    return products;
  }

  /** Выдать то, что можно выдать сейчас. Возвращает, сколько заказов обработано. */
  public int deliver(Iterable<Order> orders) throws IOException {
    int done = 0;
    for (Order order : orders) {
      if (journal.contains(order.id())) {
        continue; // уже выдан, ждёт подтверждения сайта
      }
      Product product = products.get(order.code());
      if (product == null) {
        if (warnedUnknown.add(order.code())) {
          log.warning("Заказ #" + order.id() + ": нет файла products/" + order.code() + ".yml, ждёт в очереди");
        }
        continue;
      }
      if (!NICK.matcher(order.name()).matches()) {
        journal.add(order.id(), "кривой ник в заказе");
        done++;
        continue;
      }
      boolean online = server.isOnline(order.name());
      if (product.requireOnline() && !online) {
        continue; // выдадим, когда зайдёт
      }
      String uuid = server.uuidOf(order.name());
      String failed = null;
      for (String template : product.commands()) {
        String cmd = template
            .replace("{player}", order.name())
            .replace("{uuid}", uuid)
            .replace("{order}", Long.toString(order.id()))
            .replace("{title}", order.title().replaceAll("[\\x00-\\x1f]", " "));
        boolean ok;
        try {
          ok = server.dispatch(cmd);
        } catch (RuntimeException e) {
          ok = false;
          log.warning("Заказ #" + order.id() + ": команда «" + cmd + "» упала: " + e);
        }
        if (!ok) {
          failed = "команда не выполнилась: " + cmd;
          break;
        }
      }
      journal.add(order.id(), failed);
      done++;
      if (failed == null) {
        log.info("Выдан заказ #" + order.id() + " «" + order.title() + "» игроку " + order.name());
        if (online) {
          server.message(order.name(), product.message().isEmpty() ? defaultMessage : product.message(), order.title());
        }
      } else {
        log.warning("Заказ #" + order.id() + " не выдан: " + failed);
      }
    }
    return done;
  }
}
