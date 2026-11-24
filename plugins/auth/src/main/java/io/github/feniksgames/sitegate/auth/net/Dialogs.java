package io.github.feniksgames.sitegate.auth.net;

import com.velocitypowered.api.network.ProtocolVersion;
import com.velocitypowered.proxy.protocol.packet.chat.ComponentHolder;
import java.util.List;
import net.kyori.adventure.nbt.BinaryTag;
import net.kyori.adventure.nbt.BinaryTagTypes;
import net.kyori.adventure.nbt.CompoundBinaryTag;
import net.kyori.adventure.nbt.ListBinaryTag;
import net.kyori.adventure.text.Component;

/**
 * Диалоги (экраны Minecraft 1.21.6+) в виде NBT для пакета show_dialog.
 * Формат: https://minecraft.wiki/w/Dialog . Все наши кнопки шлют custom-событие с id из
 * пространства {@value #NAMESPACE}, его ловит {@link ConfigChannel}.
 */
public final class Dialogs {

  public static final String NAMESPACE = "sitegateauth";
  public static final String CAPTCHA_PREFIX = NAMESPACE + ":captcha/";
  public static final String LEAVE = NAMESPACE + ":leave";

  private Dialogs() {
  }

  /** Капча: текст с вопросом и кнопки с числами, внизу «Выйти». */
  public static CompoundBinaryTag captcha(
      ProtocolVersion v, Component title, Component prompt, List<Integer> options, Component leave) {
    ListBinaryTag.Builder<CompoundBinaryTag> actions = ListBinaryTag.builder(BinaryTagTypes.COMPOUND);
    for (int i = 0; i < options.size(); i++) {
      actions.add(button(text(v, Component.text(options.get(i))), 60, custom(CAPTCHA_PREFIX + i)));
    }
    return CompoundBinaryTag.builder()
        .putString("type", "minecraft:multi_action")
        .put("title", text(v, title))
        .put("body", body(v, prompt))
        .putBoolean("can_close_with_escape", false)
        .putBoolean("pause", false)
        // После клика клиент ждёт следующий экран от сервера.
        .putString("after_action", "wait_for_response")
        .put("actions", actions.build())
        .putInt("columns", 3)
        .put("exit_action", button(text(v, leave), 150, custom(LEAVE)))
        .build();
  }

  /** Экран входа: кнопка открывает ссылку на сайте, окно остаётся, пока не подтвердят вход. */
  public static CompoundBinaryTag login(
      ProtocolVersion v, Component title, Component body, Component hint, Component button,
      String url, Component leave) {
    ListBinaryTag bodyList = ListBinaryTag.builder(BinaryTagTypes.COMPOUND)
        .add(message(v, body))
        .add(message(v, hint))
        .build();
    ListBinaryTag actions = ListBinaryTag.builder(BinaryTagTypes.COMPOUND)
        .add(button(text(v, button), 250, openUrl(url)))
        .build();
    return CompoundBinaryTag.builder()
        .putString("type", "minecraft:multi_action")
        .put("title", text(v, title))
        .put("body", bodyList)
        .putBoolean("can_close_with_escape", false)
        .putBoolean("pause", false)
        // Окно не закрывается после клика: игрок вернётся из браузера и увидит его же.
        .putString("after_action", "none")
        .put("actions", actions)
        .putInt("columns", 1)
        .put("exit_action", button(text(v, leave), 150, custom(LEAVE)))
        .build();
  }

  private static ListBinaryTag body(ProtocolVersion v, Component contents) {
    return ListBinaryTag.builder(BinaryTagTypes.COMPOUND).add(message(v, contents)).build();
  }

  private static CompoundBinaryTag message(ProtocolVersion v, Component contents) {
    return CompoundBinaryTag.builder()
        .putString("type", "minecraft:plain_message")
        .put("contents", text(v, contents))
        .putInt("width", 280)
        .build();
  }

  private static CompoundBinaryTag button(BinaryTag label, int width, CompoundBinaryTag action) {
    return CompoundBinaryTag.builder()
        .put("label", label)
        .putInt("width", width)
        .put("action", action)
        .build();
  }

  private static CompoundBinaryTag custom(String id) {
    return CompoundBinaryTag.builder()
        .putString("type", "minecraft:custom")
        .putString("id", id)
        .build();
  }

  private static CompoundBinaryTag openUrl(String url) {
    return CompoundBinaryTag.builder()
        .putString("type", "minecraft:open_url")
        .putString("url", url)
        .build();
  }

  /** Текстовый компонент в сетевом NBT-формате нужной версии (так же кодирует сам Velocity). */
  static BinaryTag text(ProtocolVersion v, Component c) {
    return new ComponentHolder(v, c).getBinaryTag();
  }
}
