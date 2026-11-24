package io.github.feniksgames.sitegate.auth;

import com.electronwill.nightconfig.core.UnmodifiableConfig;
import com.electronwill.nightconfig.core.file.CommentedFileConfig;
import com.electronwill.nightconfig.toml.TomlFormat;
import java.io.IOException;
import java.io.InputStream;
import java.net.URI;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.util.HashMap;
import java.util.Map;
import java.util.Objects;

/** Настройки из plugins/sitegateauth/config.toml. */
public record AuthConfig(
    URI siteUrl,
    String token,
    Duration requestTimeout,
    Duration pollInterval,
    Duration waitTimeout,
    Duration cacheTtl,
    int loginsPerMinute,
    int captchaButtons,
    int captchaAttempts,
    Duration captchaBan,
    Messages messages) {

  public AuthConfig {
    Objects.requireNonNull(siteUrl, "siteUrl");
    Objects.requireNonNull(token, "token");
    Objects.requireNonNull(messages, "messages");
    if (!"https".equals(siteUrl.getScheme()) && !"http".equals(siteUrl.getScheme())) {
      throw new IllegalArgumentException("site.url должен начинаться с https:// или http://");
    }
    if (captchaButtons < 2 || captchaButtons > 12) {
      throw new IllegalArgumentException("captcha.buttons: от 2 до 12");
    }
    if (captchaAttempts < 1) {
      throw new IllegalArgumentException("captcha.attempts: минимум 1");
    }
    if (loginsPerMinute < 1) {
      throw new IllegalArgumentException("auth.logins-per-minute: минимум 1");
    }
  }

  /** Читает config.toml; если файла нет, кладёт туда копию из jar. */
  public static AuthConfig load(Path dataDir) throws IOException {
    Files.createDirectories(dataDir);
    Path file = dataDir.resolve("config.toml");
    if (Files.notExists(file)) {
      try (InputStream in = AuthConfig.class.getResourceAsStream("/config.toml")) {
        if (in == null) {
          throw new IOException("в jar нет config.toml");
        }
        Files.copy(in, file);
      }
    }
    try (CommentedFileConfig cfg = CommentedFileConfig.of(file, TomlFormat.instance())) {
      cfg.load();
      return fromConfig(cfg, readToken(cfg, dataDir));
    }
  }

  /**
   * Токен берётся либо прямо из site.token, либо из файла site.token-file (docker secret).
   * Задать оба сразу нельзя: непонятно, какой из них настоящий.
   */
  static String readToken(UnmodifiableConfig cfg, Path dataDir) throws IOException {
    String inline = cfg.<String>getOrElse("site.token", "").trim();
    String fileName = cfg.<String>getOrElse("site.token-file", "").trim();
    if (fileName.isEmpty()) {
      return inline;
    }
    if (!inline.isEmpty()) {
      throw new IllegalArgumentException("заданы и site.token, и site.token-file: оставьте что-то одно");
    }
    Path tokenFile = dataDir.resolve(fileName);
    if (!Files.isRegularFile(tokenFile)) {
      throw new IOException("site.token-file: нет файла " + tokenFile.toAbsolutePath());
    }
    String token = Files.readString(tokenFile).trim();
    if (token.isEmpty()) {
      throw new IOException("site.token-file: файл " + tokenFile.toAbsolutePath() + " пустой");
    }
    return token;
  }

  static AuthConfig fromConfig(UnmodifiableConfig cfg, String token) {
    String url = cfg.getOrElse("site.url", "");
    while (url.endsWith("/")) {
      url = url.substring(0, url.length() - 1);
    }
    Map<String, String> texts = new HashMap<>();
    UnmodifiableConfig section = cfg.get("messages");
    if (section != null) {
      for (UnmodifiableConfig.Entry entry : section.entrySet()) {
        if (entry.<Object>getValue() instanceof String s) {
          texts.put(entry.getKey(), s);
        }
      }
    }
    return new AuthConfig(
        URI.create(url),
        token,
        Duration.ofSeconds(cfg.getIntOrElse("site.timeout-seconds", 5)),
        Duration.ofSeconds(cfg.getIntOrElse("auth.poll-seconds", 3)),
        Duration.ofMinutes(cfg.getIntOrElse("auth.wait-minutes", 10)),
        Duration.ofDays(cfg.getIntOrElse("auth.cache-days", 7)),
        cfg.getIntOrElse("auth.logins-per-minute", 5),
        cfg.getIntOrElse("captcha.buttons", 6),
        cfg.getIntOrElse("captcha.attempts", 3),
        Duration.ofMinutes(cfg.getIntOrElse("captcha.ban-minutes", 5)),
        new Messages(texts));
  }
}
