package io.github.feniksgames.sitegate.shop;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.ExecutionException;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.TimeoutException;
import java.util.concurrent.atomic.AtomicBoolean;
import net.kyori.adventure.text.minimessage.MiniMessage;
import net.kyori.adventure.text.minimessage.tag.resolver.Placeholder;
import org.bukkit.Bukkit;
import org.bukkit.command.Command;
import org.bukkit.command.CommandExecutor;
import org.bukkit.command.CommandSender;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.Listener;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.plugin.java.JavaPlugin;

/**
 * SiteGateShop: забирает с сайта оплаченные заказы и выдаёт их на сервере командами из products/*.yml.
 * Опрос сайта идёт в фоне, команды выполняются в главном потоке.
 */
public final class SiteGateShopPlugin extends JavaPlugin implements Listener, CommandExecutor {

  private static final MiniMessage MM = MiniMessage.miniMessage();

  private ShopSettings settings;
  private ShopApi api;
  private Journal journal;
  private Delivery delivery;
  private final AtomicBoolean polling = new AtomicBoolean();
  private volatile String lastProblem = "";

  @Override
  public void onEnable() {
    saveDefaultConfig();
    Path dataDir = getDataFolder().toPath();
    if (!Files.isDirectory(dataDir.resolve("products"))) {
      saveResource("products/example_diamonds.yml", false);
    }
    var command = getCommand("shop");
    if (command != null) {
      command.setExecutor(this);
    }
    try {
      settings = ShopSettings.from(getConfig(), dataDir);
    } catch (Exception e) {
      getLogger().severe("config.yml не прочитался, магазин выключен: " + e.getMessage());
      return;
    }
    if (settings.token().isEmpty()) {
      getLogger().severe("В config.yml пустой site.token (и нет site.token-file): магазин выключен.");
      return;
    }
    try {
      journal = new Journal(dataDir.resolve("delivered.json"));
      journal.load();
      Map<String, Product> products = ProductLoader.load(dataDir.resolve("products"), getLogger());
      delivery = new Delivery(new BukkitServer(settings.message("prefix")), journal, products,
          settings.message("delivered"), getLogger());
    } catch (Exception e) {
      getLogger().severe("Не прочитались products/ или delivered.json, магазин выключен: " + e);
      return;
    }
    api = new SiteShopApi(settings.siteUrl(), settings.token(), settings.timeout(), getPluginMeta().getVersion());
    getServer().getPluginManager().registerEvents(this, this);
    syncCodes();
    long period = 20L * settings.pollSeconds();
    Bukkit.getScheduler().runTaskTimerAsynchronously(this, this::poll, 100L, period);
    getLogger().info("SiteGateShop включён: сайт " + settings.siteUrl() + ", товаров " + delivery.products().size()
        + ", ждут подтверждения " + journal.size());
  }

  @EventHandler
  public void onJoin(PlayerJoinEvent event) {
    if (api != null) {
      // Покупки «только онлайн» выдаём через пару секунд после входа
      Bukkit.getScheduler().runTaskLaterAsynchronously(this, this::poll, 60L);
    }
  }

  /** Фоновый опрос: подтвердить выданное, забрать новые заказы, выдать в главном потоке. */
  private void poll() {
    if (!polling.compareAndSet(false, true)) {
      return;
    }
    try {
      confirm();
      List<Order> orders = api.pending(50).get(settings.timeout().toSeconds() + 5, TimeUnit.SECONDS);
      if (!orders.isEmpty()) {
        Bukkit.getScheduler().callSyncMethod(this, () -> delivery.deliver(orders)).get(60, TimeUnit.SECONDS);
        confirm();
      }
      problem("");
    } catch (ExecutionException e) {
      problem("сайт магазина недоступен: " + (e.getCause() != null ? e.getCause().getMessage() : e.getMessage()));
    } catch (TimeoutException e) {
      problem("сайт магазина не ответил вовремя");
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
    } catch (Exception e) {
      problem("ошибка опроса: " + e);
    } finally {
      polling.set(false);
    }
  }

  private void confirm() throws Exception {
    Journal.Snapshot s = journal.snapshot();
    if (!s.isEmpty()) {
      api.delivered(s.ok(), s.failed()).get(settings.timeout().toSeconds() + 5, TimeUnit.SECONDS);
      journal.confirm(s);
    }
  }

  /** Пишем в лог только смену состояния, чтобы лежащий сайт не засыпал консоль. */
  private void problem(String text) {
    if (!text.equals(lastProblem)) {
      lastProblem = text;
      if (text.isEmpty()) {
        getLogger().info("Связь с сайтом магазина есть");
      } else {
        getLogger().warning(text);
      }
    }
  }

  private void syncCodes() {
    Map<String, Boolean> codes = new TreeMap<>();
    delivery.products().forEach((code, p) -> codes.put(code, p.requireOnline()));
    api.codes(codes).whenComplete((ok, err) -> {
      if (err != null) {
        getLogger().warning("Список товаров не ушёл на сайт: " + err.getMessage());
      }
    });
  }

  @Override
  public boolean onCommand(CommandSender sender, Command command, String label, String[] args) {
    if (args.length == 1 && args[0].equalsIgnoreCase("reload")) {
      if (!sender.hasPermission("sitegateshop.admin")) {
        say(sender, settings == null ? "<red>Нет прав.</red>" : settings.message("no-permission"));
        return true;
      }
      if (delivery == null) {
        say(sender, "<red>Магазин выключен: смотри лог сервера.</red>");
        return true;
      }
      try {
        delivery.setProducts(ProductLoader.load(getDataFolder().toPath().resolve("products"), getLogger()));
      } catch (Exception e) {
        say(sender, "<red>Не прочитались products/: " + MM.escapeTags(e.getMessage()) + "</red>");
        return true;
      }
      syncCodes();
      say(sender, settings.message("reloaded").replace("<count>", Integer.toString(delivery.products().size())));
      return true;
    }
    if (api == null) {
      say(sender, settings == null ? "<red>Магазин на сервере не настроен.</red>" : settings.message("disabled"));
      return true;
    }
    if (!(sender instanceof Player player)) {
      say(sender, "Баланс смотрят игроки в игре: /shop");
      return true;
    }
    String url = settings.siteUrl() + "/shop";
    api.balance(player.getName()).whenComplete((b, err) -> Bukkit.getScheduler().runTask(this, () -> {
      if (err != null) {
        say(player, settings.message("site-down"));
      } else if (!b.registered()) {
        say(player, settings.message("not-registered"));
      } else {
        say(player, settings.message("balance").replace("<url>", url), "balance", Long.toString(b.balance()));
      }
    }));
    return true;
  }

  private void say(CommandSender to, String miniMessage, String... pairs) {
    String prefix = settings == null ? "" : settings.message("prefix");
    var resolvers = new java.util.ArrayList<net.kyori.adventure.text.minimessage.tag.resolver.TagResolver>();
    for (int i = 0; i + 1 < pairs.length; i += 2) {
      resolvers.add(Placeholder.unparsed(pairs[i], pairs[i + 1]));
    }
    to.sendMessage(MM.deserialize(prefix + miniMessage,
        net.kyori.adventure.text.minimessage.tag.resolver.TagResolver.resolver(resolvers)));
  }
}
