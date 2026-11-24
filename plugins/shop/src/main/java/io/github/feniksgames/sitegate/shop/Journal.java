package io.github.feniksgames.sitegate.shop;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.reflect.TypeToken;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Журнал выданного, но ещё не подтверждённого сайту. Пишется на диск сразу после выдачи,
 * поэтому если сайт не ответил или сервер упал, заказ не выдастся второй раз: при следующем
 * опросе плагин просто повторит подтверждение. Значение: null = выдан, строка = ошибка выдачи.
 */
public final class Journal {

  // serializeNulls: выданный заказ хранится как null, без этого Gson молча выкинет его из файла
  private static final Gson GSON = new GsonBuilder().serializeNulls().create();

  private final Path file;
  private final Map<Long, String> entries = new LinkedHashMap<>();

  public Journal(Path file) {
    this.file = file;
  }

  public synchronized void load() throws IOException {
    entries.clear();
    if (Files.exists(file)) {
      Map<String, String> raw = GSON.fromJson(Files.readString(file, StandardCharsets.UTF_8),
          new TypeToken<Map<String, String>>() { }.getType());
      if (raw != null) {
        raw.forEach((k, v) -> entries.put(Long.parseLong(k), v));
      }
    }
  }

  public synchronized boolean contains(long id) {
    return entries.containsKey(id);
  }

  public synchronized void add(long id, String error) throws IOException {
    entries.put(id, error);
    save();
  }

  /** Снимок: выданные и ошибки. */
  public synchronized Snapshot snapshot() {
    List<Long> ok = entries.entrySet().stream().filter(e -> e.getValue() == null).map(Map.Entry::getKey).toList();
    Map<Long, String> failed = new LinkedHashMap<>();
    entries.forEach((k, v) -> {
      if (v != null) {
        failed.put(k, v);
      }
    });
    return new Snapshot(ok, failed);
  }

  /** Сайт подтвердил приём: убрать эти записи. */
  public synchronized void confirm(Snapshot s) throws IOException {
    s.ok().forEach(entries::remove);
    s.failed().keySet().forEach(entries::remove);
    save();
  }

  public synchronized int size() {
    return entries.size();
  }

  private void save() throws IOException {
    Map<String, String> raw = new LinkedHashMap<>();
    entries.forEach((k, v) -> raw.put(Long.toString(k), v));
    Files.createDirectories(file.getParent());
    Path tmp = file.resolveSibling(file.getFileName() + ".tmp");
    Files.writeString(tmp, GSON.toJson(raw), StandardCharsets.UTF_8);
    Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING, StandardCopyOption.ATOMIC_MOVE);
  }

  public record Snapshot(List<Long> ok, Map<Long, String> failed) {
    public boolean isEmpty() {
      return ok.isEmpty() && failed.isEmpty();
    }
  }
}
