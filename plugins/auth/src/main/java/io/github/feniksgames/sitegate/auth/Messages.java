package io.github.feniksgames.sitegate.auth;

import java.util.Map;
import net.kyori.adventure.text.Component;
import net.kyori.adventure.text.minimessage.MiniMessage;
import net.kyori.adventure.text.minimessage.tag.resolver.Placeholder;
import net.kyori.adventure.text.minimessage.tag.resolver.TagResolver;

/** Тексты из [messages] в config.toml. Плейсхолдеры подставляются как простой текст, без разметки. */
public final class Messages {

  private static final MiniMessage MM = MiniMessage.miniMessage();

  private final Map<String, String> texts;

  public Messages(Map<String, String> texts) {
    this.texts = Map.copyOf(texts);
  }

  /**
   * Рендерит текст по ключу.
   *
   * @param pairs пары «имя плейсхолдера, значение»
   */
  public Component get(String key, String... pairs) {
    if (pairs.length % 2 != 0) {
      throw new IllegalArgumentException("плейсхолдеры передаются парами");
    }
    String raw = texts.getOrDefault(key, key);
    TagResolver[] resolvers = new TagResolver[pairs.length / 2];
    for (int i = 0; i < pairs.length; i += 2) {
      resolvers[i / 2] = Placeholder.unparsed(pairs[i], pairs[i + 1]);
    }
    return MM.deserialize(raw, resolvers);
  }
}
