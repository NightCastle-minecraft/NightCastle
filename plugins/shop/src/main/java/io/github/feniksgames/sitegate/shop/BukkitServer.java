package io.github.feniksgames.sitegate.shop;

import java.nio.charset.StandardCharsets;
import java.util.UUID;
import net.kyori.adventure.text.minimessage.MiniMessage;
import net.kyori.adventure.text.minimessage.tag.resolver.Placeholder;
import org.bukkit.Bukkit;
import org.bukkit.command.CommandException;
import org.bukkit.entity.Player;

/** Delivery.Server поверх Bukkit. Только главный поток. */
final class BukkitServer implements Delivery.Server {

  private final String prefix;

  BukkitServer(String prefix) {
    this.prefix = prefix;
  }

  @Override
  public boolean isOnline(String name) {
    return Bukkit.getPlayerExact(name) != null;
  }

  @Override
  public String uuidOf(String name) {
    Player p = Bukkit.getPlayerExact(name);
    if (p != null) {
      return p.getUniqueId().toString();
    }
    // Сервер за Velocity в offline-mode: UUID игрока офлайновый, считаем его сами (без запроса к Mojang)
    return UUID.nameUUIDFromBytes(("OfflinePlayer:" + name).getBytes(StandardCharsets.UTF_8)).toString();
  }

  @Override
  public boolean dispatch(String command) {
    try {
      return Bukkit.dispatchCommand(Bukkit.getConsoleSender(), command);
    } catch (CommandException e) {
      Bukkit.getLogger().warning("[SiteGateShop] команда «" + command + "» упала: " + e.getMessage());
      return false;
    }
  }

  @Override
  public void message(String name, String miniMessage, String title) {
    Player p = Bukkit.getPlayerExact(name);
    if (p != null) {
      p.sendMessage(MiniMessage.miniMessage().deserialize(prefix + miniMessage, Placeholder.unparsed("title", title)));
    }
  }
}
