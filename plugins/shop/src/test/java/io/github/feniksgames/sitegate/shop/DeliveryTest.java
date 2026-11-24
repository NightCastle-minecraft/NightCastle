package io.github.feniksgames.sitegate.shop;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.file.Path;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.logging.Logger;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class DeliveryTest {

  @TempDir
  Path dir;

  private final Set<String> online = new HashSet<>();
  private final List<String> ran = new ArrayList<>();
  private final List<String> messages = new ArrayList<>();
  private final Set<String> broken = new HashSet<>();
  private Journal journal;
  private Delivery delivery;

  private final Delivery.Server server = new Delivery.Server() {
    @Override
    public boolean isOnline(String name) {
      return online.contains(name);
    }

    @Override
    public String uuidOf(String name) {
      return "uuid-" + name;
    }

    @Override
    public boolean dispatch(String command) {
      ran.add(command);
      return broken.stream().noneMatch(command::startsWith);
    }

    @Override
    public void message(String name, String miniMessage, String title) {
      messages.add(name + ":" + miniMessage + ":" + title);
    }
  };

  @BeforeEach
  void setUp() throws Exception {
    journal = new Journal(dir.resolve("delivered.json"));
    journal.load();
    delivery = new Delivery(server, journal, Map.of(
        "diamonds", new Product("diamonds", true, List.of("give {player} diamond 16"), ""),
        "vip", new Product("vip", false, List.of("lp user {player} parent addtemp vip 30d", "say {title} для {uuid} #{order}"), "<green>VIP!")),
        "<green>Выдано: <title>", Logger.getAnonymousLogger());
  }

  @Test
  void offlineProductIsDeliveredRightAway() throws Exception {
    assertEquals(1, delivery.deliver(List.of(new Order(7, "Steve", "vip", "VIP 30 дней"))));
    assertEquals(List.of("lp user Steve parent addtemp vip 30d", "say VIP 30 дней для uuid-Steve #7"), ran);
    assertEquals(List.of(7L), journal.snapshot().ok());
    assertTrue(messages.isEmpty(), "игрока нет в игре: сообщение не шлём");
  }

  @Test
  void onlineOnlyProductWaitsForPlayer() throws Exception {
    Order o = new Order(8, "Alex", "diamonds", "Алмазы");
    assertEquals(0, delivery.deliver(List.of(o)));
    assertTrue(ran.isEmpty());
    online.add("Alex");
    assertEquals(1, delivery.deliver(List.of(o)));
    assertEquals(List.of("give Alex diamond 16"), ran);
    assertEquals(List.of("Alex:<green>Выдано: <title>:Алмазы"), messages, "стандартное сообщение");
  }

  @Test
  void alreadyDeliveredIsNotRepeated() throws Exception {
    Order o = new Order(9, "Steve", "vip", "VIP");
    delivery.deliver(List.of(o));
    delivery.deliver(List.of(o)); // сайт ещё не получил подтверждение и прислал заказ снова
    assertEquals(2, ran.size(), "две команды одного заказа, без повтора");
  }

  @Test
  void journalSurvivesRestart() throws Exception {
    delivery.deliver(List.of(new Order(10, "Steve", "vip", "VIP")));
    Journal reloaded = new Journal(dir.resolve("delivered.json"));
    reloaded.load();
    assertTrue(reloaded.contains(10));
    reloaded.confirm(reloaded.snapshot());
    Journal again = new Journal(dir.resolve("delivered.json"));
    again.load();
    assertEquals(0, again.size(), "после подтверждения сайтом журнал пуст");
  }

  @Test
  void failedCommandStopsAndIsReported() throws Exception {
    broken.add("lp ");
    delivery.deliver(List.of(new Order(11, "Steve", "vip", "VIP")));
    assertEquals(1, ran.size(), "после упавшей команды остальные не выполняем");
    assertEquals(Map.of(11L, "команда не выполнилась: lp user Steve parent addtemp vip 30d"), journal.snapshot().failed());
  }

  @Test
  void unknownProductStaysInQueueAndBadNickFails() throws Exception {
    assertEquals(0, delivery.deliver(List.of(new Order(12, "Steve", "nope", "?"))));
    assertEquals(0, journal.size());
    online.add("x y");
    delivery.deliver(List.of(new Order(13, "x y", "vip", "VIP")));
    assertTrue(ran.isEmpty(), "команду с кривым ником не выполняем");
    assertEquals(Map.of(13L, "кривой ник в заказе"), journal.snapshot().failed());
  }

  @Test
  void controlCharactersInTitleAreFlattened() throws Exception {
    delivery.deliver(List.of(new Order(14, "Steve", "vip", "VIP\nop Hacker")));
    assertEquals("say VIP op Hacker для uuid-Steve #14", ran.get(1));
  }
}
