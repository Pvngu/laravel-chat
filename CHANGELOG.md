# Changelog

All notable changes to `pvngu/laravel-chat` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

First version.

- Direct messages (one per pair of people), group conversations and public or private channels.
- Messages with replies, forwards, edits, soft deletes, pins, emoji reactions and attachments on any Laravel disk,
  served through expiring signed links (or the disk's own temporary URLs).
- Per-person state: read position (unread counts), pinned, favorite and archived conversations.
- JSON API in `@pvngu/vue-chat`'s shapes, with policies on every endpoint and 404s for chats you can't see.
- `ChatUserProvider` for apps with hashed ids, UUID keys, custom names and avatars, and multi-tenancy.
- `Chat` facade for sending messages and creating conversations from code.
- GIF messages (`gif` on messages), accepted only from the configured media hosts (KLIPY's by default).
- Mentions (`<@publicId>` in the text): recorded per message, returned as `mentions`, counted unread per chat
  (`mentionCount`) and shown by name in previews and reply quotes. `<!everyone>` and `<!here>` mention everyone in
  the chat, or everyone online.
- Membership lines: joining, leaving, adding and removing people post a line in the chat (`event` on messages,
  `Chat::addMember()`, `Chat::removeMember()`); they don't count as unread or notify anyone.
- Presence: online, away and offline. A presence channel per tenant (joining it is coming online), heartbeats with
  your status (`POST presence`), `.presence.changed` when someone goes away or comes back, and `GET presence` for the
  current statuses. Online on any connection counts; `@here` reaches people who are online. `ChatPresence` lets apps
  use their own.
- Notification settings per person and chat (all, mentions, none, and a temporary mute), with `Chat::recipients()`
  for apps that send push or email notifications.
- Settings: admins change a channel's name, description and privacy, or a group's name (`PATCH {kind}/{id}`,
  `Chat::update()`); conversations and channels show `isAdmin`, `createdAt` and `createdBy`.
- Events (`MessageSent`, `MessageUpdated`, `MessageDeleted`, `ConversationJoined`, `ConversationUpdated`) broadcast
  on private channels, with channel authorization.
- `chat:install` command.
- Model relations declare their related models (generic `@return` types), so Larastan and IDEs know them.
- Tests on Orchestra Testbench; CI on PHP 8.2–8.4 with Laravel 12 and 13.
