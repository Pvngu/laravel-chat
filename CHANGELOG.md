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
- Events (`MessageSent`, `MessageUpdated`, `MessageDeleted`, `ConversationJoined`) broadcast on private channels, with
  channel authorization.
- `chat:install` command.
- Tests on Orchestra Testbench; CI on PHP 8.2–8.4 with Laravel 12 and 13.
