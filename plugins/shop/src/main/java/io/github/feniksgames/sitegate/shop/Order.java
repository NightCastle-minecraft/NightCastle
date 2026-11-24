package io.github.feniksgames.sitegate.shop;

/** Заказ из очереди сайта (GET /api/shop/pending). */
public record Order(long id, String name, String code, String title) {
}
