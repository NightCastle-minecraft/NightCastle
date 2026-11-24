package io.github.feniksgames.sitegate.auth.store;

import com.google.gson.Gson;
import com.google.gson.JsonParseException;
import com.google.gson.reflect.TypeToken;
import java.io.IOException;
import java.lang.reflect.Type;
import java.nio.charset.StandardCharsets;
import java.nio.file.AtomicMoveNotSupportedException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.time.Duration;
import java.util.HashMap;
import java.util.Locale;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.function.LongSupplier;

/**
 * Локальная копия сессий «ник + IP → до какого времени».
 * Источник правды — сайт. Копия нужна, только когда сайт недоступен: тогда игрок с живой сессией
 * всё равно заходит. Новым игрокам и новым IP без сайта входа нет.
 */
public final class SessionCache {

  private static final Gson GSON = new Gson();
  private static final Type TYPE = new TypeToken<Map<String, Entry>>() { }.getType();

  private final Path file;
  private final long ttlMs;
  private final LongSupplier clock;
  private final Map<String, Entry> map = new ConcurrentHashMap<>();

  record Entry(String ip, long expiresAt) {}

  public SessionCache(Path file, Duration ttl, LongSupplier clockMillis) {
    this.file = file;
    this.ttlMs = ttl.toMillis();
    this.clock = clockMillis;
  }

  /** Загружает файл; битый или отсутствующий файл = пустой кеш. */
  public void load() throws IOException {
    map.clear();
    if (Files.notExists(file)) {
      return;
    }
    String text = Files.readString(file, StandardCharsets.UTF_8);
    try {
      Map<String, Entry> loaded = GSON.fromJson(text, TYPE);
      if (loaded != null) {
        long now = clock.getAsLong();
        loaded.forEach((k, v) -> {
          if (v != null && v.ip() != null && v.expiresAt() > now) {
            map.put(k, v);
          }
        });
      }
    } catch (JsonParseException e) {
      // Кеш не критичен: начинаем с пустого.
    }
  }

  /** Атомарно пишет файл (через временный). */
  public synchronized void save() throws IOException {
    purge();
    Files.createDirectories(file.getParent());
    Path tmp = file.resolveSibling(file.getFileName() + ".tmp");
    Files.writeString(tmp, GSON.toJson(new HashMap<>(map), TYPE), StandardCharsets.UTF_8);
    try {
      Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING, StandardCopyOption.ATOMIC_MOVE);
    } catch (AtomicMoveNotSupportedException e) {
      Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING);
    }
  }

  public void put(String name, String ip) {
    map.put(key(name), new Entry(ip, clock.getAsLong() + ttlMs));
  }

  public void remove(String name) {
    map.remove(key(name));
  }

  /** Есть ли живая сессия этого ника именно с этого IP. */
  public boolean isValid(String name, String ip) {
    Entry e = map.get(key(name));
    return e != null && e.ip().equals(ip) && e.expiresAt() > clock.getAsLong();
  }

  public int size() {
    return map.size();
  }

  private void purge() {
    long now = clock.getAsLong();
    map.values().removeIf(e -> e.expiresAt() <= now);
  }

  private static String key(String name) {
    return name.toLowerCase(Locale.ROOT);
  }
}
