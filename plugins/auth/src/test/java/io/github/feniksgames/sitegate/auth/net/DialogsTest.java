package io.github.feniksgames.sitegate.auth.net;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.velocitypowered.api.network.ProtocolVersion;
import com.velocitypowered.proxy.protocol.ProtocolUtils;
import io.netty.buffer.ByteBuf;
import io.netty.buffer.Unpooled;
import java.util.List;
import net.kyori.adventure.nbt.BinaryTagTypes;
import net.kyori.adventure.nbt.CompoundBinaryTag;
import net.kyori.adventure.nbt.ListBinaryTag;
import net.kyori.adventure.text.Component;
import org.junit.jupiter.api.Test;

/** Структура NBT диалогов. Что её реально принимает клиент 26.3, проверяется руками (шаг 0 плана). */
class DialogsTest {

  private static final ProtocolVersion V = ProtocolVersion.MINECRAFT_26_3;

  @Test
  void captchaDialogShape() {
    CompoundBinaryTag d = Dialogs.captcha(V, Component.text("T"), Component.text("2 + 3?"),
        List.of(5, 7, 4), Component.text("Выйти"));
    assertEquals("minecraft:multi_action", d.getString("type"));
    assertFalse(d.getBoolean("can_close_with_escape", true));
    assertEquals("wait_for_response", d.getString("after_action"));
    assertNotNull(d.get("title"));

    ListBinaryTag actions = d.getList("actions");
    assertEquals(BinaryTagTypes.COMPOUND, actions.elementType());
    assertEquals(3, actions.size());
    for (int i = 0; i < 3; i++) {
      CompoundBinaryTag action = actions.getCompound(i).getCompound("action");
      assertEquals("minecraft:custom", action.getString("type"));
      assertEquals("sitegateauth:captcha/" + i, action.getString("id"));
      assertNotNull(actions.getCompound(i).get("label"));
    }
    CompoundBinaryTag exit = d.getCompound("exit_action").getCompound("action");
    assertEquals(Dialogs.LEAVE, exit.getString("id"));

    ListBinaryTag body = d.getList("body");
    assertEquals("minecraft:plain_message", body.getCompound(0).getString("type"));
  }

  @Test
  void loginDialogOpensSiteAndStaysOpen() {
    String url = "https://example.com/auth/1abcdefghijklmnopqrstuvwxyz";
    CompoundBinaryTag d = Dialogs.login(V, Component.text("T"), Component.text("body"),
        Component.text("hint"), Component.text("Нажмите, чтобы авторизоваться"), url,
        Component.text("Выйти"));
    assertEquals("none", d.getString("after_action"), "окно не закрывается после клика по ссылке");
    CompoundBinaryTag action = d.getList("actions").getCompound(0).getCompound("action");
    assertEquals("minecraft:open_url", action.getString("type"));
    assertEquals(url, action.getString("url"));
    assertEquals(2, d.getList("body").size());
  }

  @Test
  void customClickIdIsReadFromPacketBody() {
    // Тело пакета custom_click_action начинается с Identifier: varint длины + UTF-8.
    ByteBuf buf = Unpooled.buffer();
    ProtocolUtils.writeString(buf, "sitegateauth:captcha/3");
    buf.writeByte(0); // дальше payload, нам не нужен
    assertEquals("sitegateauth:captcha/3", ProtocolUtils.readString(buf.duplicate(), 256));
    buf.release();
  }

  @Test
  void velocityInternalsPresent() {
    assertTrue(ConfigChannel.supported(), "DialogShowPacket.nbt есть в этой версии Velocity");
  }
}
