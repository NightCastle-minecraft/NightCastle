package io.github.feniksgames.sitegate.shop;

import java.io.IOException;
import java.net.URI;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.util.HashMap;
import java.util.Map;
import org.bukkit.configuration.ConfigurationSection;
import org.bukkit.configuration.file.FileConfiguration;

/** Настройки из config.yml. Токен: site.token или файл site.token-file (docker secret). */
public record ShopSettings(URI siteUrl, String token, Duration timeout, int pollSeconds, Map<String, String> messages) {

  public static ShopSettings from(FileConfiguration cfg, Path dataDir) throws IOException {
    String url = cfg.getString("site.url", "").trim();
    while (url.endsWith("/")) {
      url = url.substring(0, url.length() - 1);
    }
    if (!url.startsWith("https://") && !url.startsWith("http://")) {
      throw new IllegalArgumentException("site.url должен начинаться с https://");
    }
    String token = cfg.getString("site.token", "").trim();
    String tokenFile = cfg.getString("site.token-file", "").trim();
    if (!tokenFile.isEmpty()) {
      if (!token.isEmpty()) {
        throw new IllegalArgumentException("заданы и site.token, и site.token-file: оставьте что-то одно");
      }
      Path f = dataDir.resolve(tokenFile);
      if (!Files.isRegularFile(f)) {
        throw new IOException("site.token-file: нет файла " + f.toAbsolutePath());
      }
      token = Files.readString(f).trim();
    }
    Map<String, String> messages = new HashMap<>();
    ConfigurationSection m = cfg.getConfigurationSection("messages");
    if (m != null) {
      for (String key : m.getKeys(false)) {
        messages.put(key, m.getString(key, ""));
      }
    }
    int poll = Math.max(5, cfg.getInt("poll-seconds", 15));
    return new ShopSettings(URI.create(url), token, Duration.ofSeconds(Math.max(1, cfg.getInt("site.timeout-seconds", 5))),
        poll, Map.copyOf(messages));
  }

  public String message(String key) {
    return messages.getOrDefault(key, key);
  }
}
