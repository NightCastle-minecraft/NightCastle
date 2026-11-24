package io.github.feniksgames.sitegate.shop;

import java.io.IOException;
import java.nio.file.DirectoryStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.logging.Logger;
import org.bukkit.configuration.file.YamlConfiguration;

/** Читает products/*.yml. Кривой файл пропускается с предупреждением, остальные работают. */
public final class ProductLoader {

  private ProductLoader() {
  }

  public static Map<String, Product> load(Path dir, Logger log) throws IOException {
    Map<String, Product> out = new TreeMap<>();
    if (!Files.isDirectory(dir)) {
      return out;
    }
    try (DirectoryStream<Path> files = Files.newDirectoryStream(dir, "*.yml")) {
      for (Path file : files) {
        String code = file.getFileName().toString().replaceFirst("\\.yml$", "");
        try {
          YamlConfiguration y = YamlConfiguration.loadConfiguration(file.toFile());
          List<String> commands = y.getStringList("commands");
          out.put(code, new Product(code, y.getBoolean("require-online", true), commands, y.getString("message", "")));
        } catch (IllegalArgumentException e) {
          log.warning("products/" + file.getFileName() + " пропущен: " + e.getMessage());
        }
      }
    }
    return out;
  }
}
