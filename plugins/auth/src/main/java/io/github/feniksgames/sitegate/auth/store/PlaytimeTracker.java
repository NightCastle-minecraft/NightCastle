package io.github.feniksgames.sitegate.auth.store;

import com.google.gson.Gson;
import com.google.gson.JsonParseException;
import com.google.gson.reflect.TypeToken;
import io.github.feniksgames.sitegate.auth.api.AuthApi;
import io.github.feniksgames.sitegate.auth.api.AuthApi.PlaySession;
import java.io.IOException;
import java.lang.reflect.Type;
import java.nio.charset.StandardCharsets;
import java.nio.file.AtomicMoveNotSupportedException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ConcurrentHashMap;
import java.util.function.LongSupplier;
import org.slf4j.Logger;

/**
 * Считает, сколько игрок провёл на сервере, и отправляет сессии на сайт.
 * Неотправленное лежит в очереди на диске и уходит при следующей попытке: сайт принимает
 * повторы без дублей (ключ = ник + начало).
 */
public final class PlaytimeTracker {

  private static final Gson GSON = new Gson();
  private static final Type TYPE = new TypeToken<List<PlaySession>>() { }.getType();
  private static final int BATCH = 200;

  private final AuthApi api;
  private final Path file;
  private final LongSupplier clockSeconds;
  private final Logger log;
  private final Map<UUID, Open> open = new ConcurrentHashMap<>();
  private final List<PlaySession> queue = new ArrayList<>();
  private boolean flushing;

  private record Open(String name, long start) {}

  public PlaytimeTracker(AuthApi api, Path file, LongSupplier clockSeconds, Logger log) {
    this.api = api;
    this.file = file;
    this.clockSeconds = clockSeconds;
    this.log = log;
  }

  public synchronized void load() throws IOException {
    queue.clear();
    if (Files.notExists(file)) {
      return;
    }
    try {
      List<PlaySession> loaded = GSON.fromJson(Files.readString(file, StandardCharsets.UTF_8), TYPE);
      if (loaded != null) {
        for (PlaySession s : loaded) {
          if (s != null && s.name() != null && s.end() > s.start()) {
            queue.add(s);
          }
        }
      }
    } catch (JsonParseException e) {
      log.warn("Очередь playtime битая, начинаю с пустой");
    }
  }

  public void started(UUID uuid, String name) {
    open.putIfAbsent(uuid, new Open(name, clockSeconds.getAsLong()));
  }

  public void stopped(UUID uuid) {
    Open o = open.remove(uuid);
    if (o == null) {
      return;
    }
    long end = clockSeconds.getAsLong();
    if (end > o.start()) {
      synchronized (this) {
        queue.add(new PlaySession(o.name(), o.start(), end));
        saveQuietly();
      }
    }
  }

  public synchronized int queued() {
    return queue.size();
  }

  /** Отправляет до {@value #BATCH} сессий. Ошибка = сессии остаются в очереди. */
  public CompletableFuture<Void> flush() {
    List<PlaySession> batch;
    synchronized (this) {
      if (flushing || queue.isEmpty()) {
        return CompletableFuture.completedFuture(null);
      }
      flushing = true;
      batch = List.copyOf(queue.subList(0, Math.min(BATCH, queue.size())));
    }
    return api.postSessions(batch).handle((ok, err) -> {
      synchronized (this) {
        flushing = false;
        if (err == null) {
          queue.removeAll(batch);
          saveQuietly();
        } else {
          log.debug("Не удалось отправить {} сессий: {}", batch.size(), err.toString());
        }
      }
      return null;
    });
  }

  /** При выключении прокси: закрыть открытые сессии и сохранить очередь. */
  public void closeAll() {
    for (UUID uuid : List.copyOf(open.keySet())) {
      stopped(uuid);
    }
    synchronized (this) {
      saveQuietly();
    }
  }

  private void saveQuietly() {
    try {
      Files.createDirectories(file.getParent());
      Path tmp = file.resolveSibling(file.getFileName() + ".tmp");
      Files.writeString(tmp, GSON.toJson(queue, TYPE), StandardCharsets.UTF_8);
      try {
        Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING, StandardCopyOption.ATOMIC_MOVE);
      } catch (AtomicMoveNotSupportedException e) {
        Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING);
      }
    } catch (IOException e) {
      log.warn("Не удалось сохранить очередь playtime: {}", e.toString());
    }
  }
}
