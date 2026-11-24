package io.github.feniksgames.sitegate.shop;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.Map;
import java.util.logging.Logger;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class ProductLoaderTest {

  @TempDir
  Path dir;

  @Test
  void bundledExampleLoads() throws Exception {
    try (InputStream in = getClass().getResourceAsStream("/products/example_diamonds.yml")) {
      Files.copy(in, dir.resolve("example_diamonds.yml"));
    }
    Map<String, Product> p = ProductLoader.load(dir, Logger.getAnonymousLogger());
    Product d = p.get("example_diamonds");
    assertTrue(d.requireOnline());
    assertEquals(List.of("give {player} minecraft:diamond 16"), d.commands());
  }

  @Test
  void badFilesAreSkipped() throws Exception {
    Files.writeString(dir.resolve("vip_30d.yml"), "require-online: false\ncommands:\n  - \"lp user {player} parent add vip\"\n");
    Files.writeString(dir.resolve("Bad-Name.yml"), "commands: [\"say x\"]\n");
    Files.writeString(dir.resolve("empty.yml"), "require-online: true\n");
    Files.writeString(dir.resolve("notes.txt"), "не товар");
    Map<String, Product> p = ProductLoader.load(dir, Logger.getAnonymousLogger());
    assertEquals(List.of("vip_30d"), List.copyOf(p.keySet()));
    assertFalse(p.get("vip_30d").requireOnline());
  }

  @Test
  void productValidation() {
    assertThrows(IllegalArgumentException.class, () -> new Product("../x", true, List.of("say"), ""));
    assertThrows(IllegalArgumentException.class, () -> new Product("ok_code", true, List.of(), ""));
  }
}
