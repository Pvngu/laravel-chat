# Changelog

All notable changes to `pvngu/laravel-chat` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-08

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
- Features: `config('laravel-chat.features')` turns off what a project doesn't need (channels, groups, threads, saved,
  reminders, reactions, replies, forwarding, editing, deleting, pinning, mentions, attachments, gifs, formatting,
  files, presence, notifications); their routes answer 404 and their fields are refused. `GET features` lists them for
  the frontend; `Features::enabled()` in code.
- Attachments served by the package's signed route only show in the browser when they're plain images, video or audio
  (`Attachment::INLINE_TYPES`); everything else (HTML, SVG, XML, PDF…) downloads as `application/octet-stream` with
  `nosniff` and a sandboxing `Content-Security-Policy`. SVGs are files, not images (`isImage`), and stay out of the
  Files tab's media.
- `ChatUserProvider::publicIdForKey()`: the public id of someone whose account was deleted, from their key alone, so
  their kept messages, reactions and mentions never show the raw key (`DefaultChatUserProvider` returns the key).
- Admins remove, or make members again, people still in their chat whom the provider's `find()` no longer returns
  (e.g. a disabled account); only people it finds can be made admins.
- `remindAt` and `mutedUntil` are stored as UTC in `DATETIME` columns (`Casts\UtcDatetime`) and read back on the
  app's clock, so reminders and mutes end when chosen in apps whose `app.timezone` isn't UTC, the night the clocks go
  back included. Apps that published the migrations earlier: change `remind_at` and `muted_until` to `dateTime` and
  convert their values to UTC (nothing to do in an app on UTC).
- `lastMessage.id` on conversations and `lastMessageId` on channels: the latest message `unreadCount` can include, so
  a client doesn't count a message twice when a chat is described after it was sent (`ConversationJoined`) and its
  `.message.sent` arrives too.
- A mention in a thread reply counts in its thread, not in the chat's `mentionCount` (nor `GET unread`), unless the
  reply was sent to the chat too, as `unreadCount` already did.
- Starting a direct message you already have (`POST conversations`) brings it back to your sidebar if you had
  archived it.
- Start a conversation with its first message: `POST conversations` takes an optional `body`, `attachments[]` or
  `gif`, saved in one transaction (`Chat::start()`); everyone else hears about it once, with the message already in
  it, and the starter's other tabs and devices hear about a new one.
- `GET conversations/lookup` and `Chat::findDirect()`: the direct message two people already have, without starting
  or un-archiving it.
- Two people starting the same direct message at the same moment inside a transaction no longer fails.
- A new message that brings back a conversation someone had archived tells them with `ConversationJoined`, so their
  sidebar shows it again and their browser follows it again; that includes the sender's own browser, when they're the
  one who had archived it.
- Unread totals: `GET unread` (`chat.unread`, `Chat::unread()`) gives `{ chats, mentions }`, what the signed-in
  person's sidebar badges add up to by vue-chat's rules (muted chats show nothing, mentions always count, other unread
  messages only in chats set to `all`; archived conversations and channels they haven't joined left out), in one
  query, for a badge outside the chat. Asking doesn't count as being online.
- Formatting: messages keep vue-chat's formatting markers (bold, italic, underline, strikethrough, code, links,
  quotes, lists, code blocks); previews, reply quotes and `displayText()` read without them (`Formatting::plain()`).
- Membership lines: joining, leaving, adding and removing people post a line in the chat (`event` on messages,
  `Chat::addMember()`, `Chat::removeMember()`); they don't count as unread or notify anyone.
- Presence: online, away and offline. A presence channel per tenant (joining it is coming online), heartbeats with
  your status (`POST presence`), `.presence.changed` when someone goes away or comes back, and `GET presence` for the
  current statuses. Online on any connection counts; `@here` reaches people who are online. `ChatPresence` lets apps
  use their own.
- Notification settings per person and chat (all, mentions, none, and a temporary mute), with `Chat::recipients()`
  for apps that send push or email notifications.
- Admins and members: each member's `role` in responses; admins make people admins or members again
  (`PATCH {kind}/{id}/members/{userId}`, `Chat::setRole()`, the `manageRoles` policy), a chat always keeps an admin,
  and only admins add people to a private channel. The creator always stays an admin that others can't remove or
  demote. The last admin leaving hands over to the member they choose
  (`successorId`) or the longest-standing one. Role changes are membership lines (`promoted`, `demoted`).
- Threads: replies to a message (`POST`/`GET messages/{id}/replies`, `Chat::send(..., thread:)`), kept out of the
  chat's messages, unread count and latest message; messages carry `threadId`, `replyCount` and `lastReplyAt`.
  `GET threads` lists the ones you follow (started, replied, mentioned) with `unreadCount`, and
  `POST messages/{id}/thread/read` reads one; replies only notify followers and mentions. A reply sent with
  `alsoSendToChat` shows in the chat too (`alsoInChat`, `threadRoot`). The threads list comes with each one's
  `latestReplies` (`pagination.thread_replies`, five by default). New migrations: `update_chat_messages_for_threads`, `update_chat_messages_for_threads_in_chat`.
- Saved messages: `PUT` / `DELETE messages/{id}/saved` (`Chat::saveMessage()`, `Chat::unsaveMessage()`) and
  `GET saved`, newest first with each message's chat. New migration: `create_chat_saved_messages_table`.
- Reminders: `PUT messages/{id}/reminder` with `{ remindAt }` (`Chat::remind()`), `DELETE` to remove it, and
  `GET reminders`. A delayed queued job fires `ReminderDue` at that time (`chat:send-reminders` catches up); apps
  listen for it to notify the person. New migration: `create_chat_reminders_table`.
- Files: `GET {kind}/{id}/files` lists a chat's photos and videos (`type=media`) or other files (`type=files`),
  newest first, with who sent each one, when, and its message, for vue-chat's Files tab
  (`laravel-chat.pagination.files`).
- Settings: admins change a channel's name, description and privacy, or a group's name (`PATCH {kind}/{id}`,
  `Chat::update()`); conversations and channels show `isAdmin`, `createdAt` and `createdBy`.
- Events (`MessageSent`, `MessageUpdated`, `MessageDeleted`, `ConversationJoined`, `ConversationUpdated`) broadcast
  on private channels, with channel authorization.
- `chat:install` command.
- Model relations declare their related models (generic `@return` types), so Larastan and IDEs know them.
- Tests on Orchestra Testbench; CI on PHP 8.2–8.4 with Laravel 12 and 13.
