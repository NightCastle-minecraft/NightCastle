package io.github.feniksgames.sitegate.shop;

import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;

/** API сайта для магазина. Контракт: docs/shop-api.md. */
public interface ShopApi {

  CompletableFuture<List<Order>> pending(int limit);

  /** Сообщить сайту: выданы ok, не выданы failed (id → текст ошибки). */
  CompletableFuture<Void> delivered(List<Long> ok, Map<Long, String> failed);

  /** Какие товары плагин умеет выдавать: код → нужен ли игрок онлайн. */
  CompletableFuture<Void> codes(Map<String, Boolean> codes);

  CompletableFuture<Balance> balance(String name);

  record Balance(boolean registered, long balance) {
  }
}
