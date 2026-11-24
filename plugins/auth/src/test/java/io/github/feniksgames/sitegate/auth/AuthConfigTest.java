package io.github.feniksgames.sitegate.auth;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import net.kyori.adventure.text.serializer.plain.PlainTextComponentSerializer;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class AuthConfigTest {

  @TempDir
  Path dir;

  @Test
  void defaultConfigIsCopiedAndParsed() throws Exception {
    AuthConfig cfg = AuthConfig.load(dir);
    assertTrue(Files.exists(dir.resolve("config.toml")));
    assertEquals("https://example.com", cfg.siteUrl().toString());
    assertEquals("", cfg.token(), "по умолчанию токен пустой = вход закрыт");
    assertEquals(Duration.ofMinutes(10), cfg.waitTimeout());
    assertEquals(6, cfg.captchaButtons());
    assertEquals(3, cfg.captchaAttempts());
  }

  @Test
  void placeholdersAreTextNotMarkup() throws Exception {
    AuthConfig cfg = AuthConfig.load(dir);
    String prompt = plain(cfg.messages().get("captcha-prompt", "x", "12", "y", "<red>35"));
    assertTrue(prompt.contains("12 + <red>35"), prompt);
    String button = plain(cfg.messages().get("login-button"));
    assertEquals("Нажмите, чтобы авторизоваться", button);
  }

  @Test
  void trailingSlashIsTrimmedAndBadSchemeRejected() throws Exception {
    Files.writeString(dir.resolve("config.toml"), "[site]\nurl = \"https://x.example/\"\ntoken = \"t\"\n");
    assertEquals("https://x.example", AuthConfig.load(dir).siteUrl().toString());

    Files.writeString(dir.resolve("config.toml"), "[site]\nurl = \"ftp://x.example\"\n");
    assertThrows(IllegalArgumentException.class, () -> AuthConfig.load(dir));
  }

  @Test
  void tokenIsReadFromFileRelativeToDataDir() throws Exception {
    Files.writeString(dir.resolve("token.txt"), "  s3cret-token\n");
    Files.writeString(dir.resolve("config.toml"), "[site]\nurl = \"https://x.example\"\ntoken-file = \"token.txt\"\n");
    assertEquals("s3cret-token", AuthConfig.load(dir).token());

    Path abs = dir.resolve("abs-token");
    Files.writeString(abs, "abs");
    Files.writeString(dir.resolve("config.toml"),
        "[site]\nurl = \"https://x.example\"\ntoken-file = \"" + abs.toAbsolutePath() + "\"\n");
    assertEquals("abs", AuthConfig.load(dir).token());
  }

  @Test
  void badTokenFileClosesTheGate() throws Exception {
    String head = "[site]\nurl = \"https://x.example\"\n";
    Files.writeString(dir.resolve("config.toml"), head + "token-file = \"missing\"\n");
    assertThrows(java.io.IOException.class, () -> AuthConfig.load(dir), "файла нет");

    Files.writeString(dir.resolve("empty"), " \n");
    Files.writeString(dir.resolve("config.toml"), head + "token-file = \"empty\"\n");
    assertThrows(java.io.IOException.class, () -> AuthConfig.load(dir), "файл пустой");

    Files.writeString(dir.resolve("t"), "x");
    Files.writeString(dir.resolve("config.toml"), head + "token = \"y\"\ntoken-file = \"t\"\n");
    assertThrows(IllegalArgumentException.class, () -> AuthConfig.load(dir), "оба сразу");
  }

  private static String plain(net.kyori.adventure.text.Component c) {
    return PlainTextComponentSerializer.plainText().serialize(c);
  }
}
