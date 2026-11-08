# pvngu/laravel-chat

Slack-style chat for any Laravel app: direct messages, group conversations and channels, replies, forwards, edits,
reactions, pins, attachments, unread counts and real-time updates. It's the backend for
[`@pvngu/vue-chat`](https://github.com/Pvngu/vue-chat): the API returns exactly the shapes the Vue components use, so
the frontend needs no mapping.

- **Secure by default.** Policies on every endpoint: only members see or post in a chat, only senders edit their
  messages, admins remove people. Other people's conversations answer 404, so ids can't be probed.
- **Fits your app.** One class says how your users are identified (database ids, hashed ids, UUIDs), named, shown
  and scoped to a tenant (e.g. a company).
- **Real-time.** Events broadcast on private channels through Reverb, Pusher or Ably; vue-chat's `connectEcho()`
  wires them into the UI.
- **Built for chat.** Read state is one "last read message" per person, not one row per message per reader; a direct
  conversation between two people exists once; attachments live on any Laravel disk and are served through
  expiring links.

Requires PHP 8.2+ and Laravel 12 or 13.

## Install

```bash
composer require pvngu/laravel-chat
php artisan chat:install     # publishes config/laravel-chat.php and the migrations
php artisan migrate
```

Check `config/laravel-chat.php` before migrating:

- `routes.middleware`: `['api', 'auth:sanctum']` by default. For a same-origin SPA with session login, `['web', 'auth']`
  also works.
- `user_key_type`: `'uuid'`, `'ulid'` or `'string'` if your users table key isn't a big integer.
- `user_attributes`: which attributes hold the name, email and avatar, and `tenant` (e.g. `'company_id'`) for
  multi-tenant apps: people only see conversations and other people with the same value.
- `features`: what the chat can do, all on by default (see Features).
- `table_prefix`: tables are `chat_conversations`, `chat_participants`, `chat_messages`, `chat_attachments`,
  `chat_reactions`, `chat_reminders`, `chat_saved_messages` and `chat_thread_followers`. [musonza/chat](https://github.com/musonza/chat) uses the same prefix; change one of them if both
  are installed.

### Frontend

```bash
npm install @pvngu/vue-chat
```

```vue
<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { ChatLayout, connectEcho, createLaravelAdapter, useChatStore } from '@pvngu/vue-chat'

const chat = useChatStore({
  adapter: createLaravelAdapter({ socketId: () => window.Echo?.socketId() }),
  currentUser: { id: user.id, name: user.name }, // id as the API exposes it (see "Your users")
})

onMounted(chat.init)
const disconnect = connectEcho(window.Echo, chat) // optional: real-time updates
onUnmounted(disconnect)
</script>

<template>
  <ChatLayout v-bind="chat.bindings" sidebar-variant="sections" />
</template>
```

`createLaravelAdapter()` calls `/api/chat` with `fetch`, sends Sanctum's XSRF cookie back, and accepts
`baseUrl`, `headers` (e.g. a bearer token) and `credentials` options. For Sanctum's cookie-based SPA authentication,
call `/sanctum/csrf-cookie` once before the first request.

## Your users

The default provider reads `config('laravel-chat.user_attributes')`. When your app needs more, extend it and point
`user_provider` at your class:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pvngu\Chat\Users\DefaultChatUserProvider;

class ChatUsers extends DefaultChatUserProvider
{
    // Expose hashed ids instead of database keys
    public function publicId(Model $user): string
    {
        return $user->xid;
    }

    // The same for someone whose account was deleted (their messages stay)
    public function publicIdForKey(int|string $key): string
    {
        return Hashids::encode($key);
    }

    public function find(string $publicId): ?Model
    {
        return User::query()->find(Hashids::decode($publicId)[0] ?? null);
    }

    // Only staff of the same company can be messaged
    public function available(Model $user): Collection
    {
        return User::query()
            ->where('company_id', $user->getRawOriginal('company_id'))
            ->where('user_type', 'staff_members')
            ->whereKeyNot($user->getKey())
            ->orderBy('name')
            ->get();
    }
}
```

The methods are `publicId()`, `publicIdForKey()`, `find()`, `present()` (name, email, avatar), `tenant()` and
`available()`; see `Pvngu\Chat\Contracts\ChatUserProvider`. `publicIdForKey()` gives the public id from the key
alone, for people whose account was deleted but whose messages, reactions and mentions remain (they show without a
name); with hashed ids, override it too, or the browser gets the raw key. `find()` decides who can be added to a chat
or messaged; people already in one stay there (listed, and removable or demoted by its admins) even when it no longer
finds them, e.g. a disabled account, until they're removed.

Searching conversations by the people in them uses the optional `Pvngu\Chat\Contracts\SearchesChatUsers`
(`whereNameContains()`). The default provider implements it for the `user_attributes.name` column; if your name is
computed (an accessor), override it to search your own columns, or conversations are only found by their name.

## Using it from code

The `Chat` facade does everything the API does, without permission checks:

```php
use Pvngu\Chat\Facades\Chat;

$conversation = Chat::direct($user, $otherUser);            // the existing one, or a new one
$group = Chat::group($user, [$ann, $john], 'Launch');
[$conversation, $message] = Chat::start($user, [$john], direct: true, name: null, body: 'Hi John'); // saved together
Chat::findDirect($user, $john);                               // their direct message, or null; changes nothing
$channel = Chat::channel($user, 'announcements');

Chat::send($channel, $bot, 'Deploy finished ✅');            // also accepts UploadedFile[] attachments
Chat::addParticipant($channel, $newHire);
Chat::addMember($channel, $newHire, by: $user);              // shows "… added …" in the chat (removeMember too)
Chat::update($channel, ['description' => 'Company news']);   // name, description, isPrivate (channels)
Chat::setNotifications($channel, $user, 'mentions');         // 'all' | 'mentions' | 'none', optional mute end
```

## Features

Each project can turn off what it doesn't need in `config/laravel-chat.php`:

```php
'features' => [
    'threads' => false,
    'reminders' => false,
    // channels, groups, saved, reactions, replies, forwarding, editing, deleting, pinning, mentions,
    // attachments, gifs, formatting, files, presence and notifications stay on
],
```

A feature that's off has its routes answer 404 (`GET threads`, `POST messages/{id}/reactions`, every `channels/…`
route…) and its fields refused (`attachments`, `gif`, `replyToId`, `forwardedFromId`; group conversations without
`groups`); mentions aren't recorded without `mentions`, and nobody is online without `presence`. `GET features` lists
them all (`{ data: { threads: false, … } }`): vue-chat's `useChatStore` reads it and leaves out what's off, so there's
nothing to repeat in the frontend. `Features::enabled('threads')` checks one in your own code.

## Mentions and notifications

A mention is `<@publicId>` in a message's text (the id your `ChatUserProvider` gives the person), which vue-chat's
message box writes when someone picks a name after `@`. The package records who each message mentions (people in the
same tenant), returns them as `mentions: [{ id, name }]`, counts unread ones per chat (`mentionCount`), and shows them
as "@Name" in previews and reply quotes. A mention in a thread reply counts in its thread (the thread's unread
count), not in the chat's `mentionCount`, unless the reply was sent to the chat too.

`<!everyone>` mentions everyone else in the chat and `<!here>` everyone else who's online, shown as "@everyone" and
"@here". They count as a mention for each of those people (unread counts, notifications) without being listed in
`mentions`, so a message to a large channel stays small.

`@here` reaches the people who are online, not away (see Presence).

## Unread totals

For a badge outside the chat (your app's menu, a tab title), `GET unread` says what the signed-in person's chat
sidebar badges add up to: `{ data: { chats: 3, mentions: 1 } }`, how many chats show a badge and how many unread
mentions those badges count. It follows vue-chat's rules (`unreadBadge`): a muted chat (`none`, or a `mutedUntil`
still to come) shows nothing, unread mentions always show, and other unread messages only in a chat set to `all`.
Like the sidebar, it leaves out archived conversations, public channels the person hasn't joined and, with the
`channels` feature off, every channel. It's one query however many chats there are, and asking doesn't count as being
around in the chat (see Presence). From code: `Chat::unread($user)`.

Each conversation's `lastMessage.id` (and each channel's `lastMessageId`) is the latest message its `unreadCount`
can include. A chat described just after a message was sent (e.g. `ConversationJoined`, which is rendered when the
queue gets to it) already counts that message, so a client that also receives its `.message.sent` shouldn't count it
again: vue-chat's store skips any message up to that id.

## Formatting

Messages keep the formatting vue-chat's message box writes as markers in their text: `**bold**`, `_italic_`,
`__underline__`, `~~strikethrough~~`, `` `code` ``, `[text](https://link)`, `> ` quotes, `- ` and `1. ` lists and
```` ``` ```` code blocks. `text` returns them as written, for the chat to show formatted; previews, reply quotes and
`Message::displayText()` (what notifications read) leave the markers out. `Formatting::plain($text)` does the same
for your own uses.

## Presence

Each person is online, away or offline, shown as green, yellow and gray dots in vue-chat:

- **Online and offline:** everyone in a tenant joins its presence channel (`chat.presence.{key}`, named by
  `Chat::presenceChannel($user)` and `GET presence`) while the chat is open. Joining and leaving it is coming online
  and going offline, in real time.
- **Away:** vue-chat sends a heartbeat (`POST presence` with `status: online | away`) every minute and whenever it
  changes: away while the page is hidden or idle. When someone's status changes, the others get `.presence.changed` on
  the presence channel. Online on any tab or device counts as online.
- `GET presence?ids=a,b,c` says who of them is online or away now (only people in your tenant), for a page that just
  joined.

The default `CachePresence` keeps what each connection (Echo socket) last reported for two minutes (`presence.ttl`),
so a closed tab drops out on its own; any chat request (but `GET unread`) also counts as being around. If your app
already tracks presence, implement `Pvngu\Chat\Contracts\ChatPresence` (`touch()` and `statuses()`) and set
`presence.driver`.

Each person chooses how they hear about a chat: every message (`all`, the default), only mentions (`mentions`), or
nothing (`none`), plus an optional `mutedUntil` to pause it for a while. The package doesn't send notifications
itself; when you do (push, email), ask it who wants one:

```php
use Pvngu\Chat\Events\MessageSent;

Event::listen(function (MessageSent $event) {
    Notification::send(Chat::recipients($event->message), new NewChatMessage($event->message));
});
```

`Chat::recipients($message)` is everyone in the chat but the sender, minus people who muted it or follow only
mentions and weren't mentioned.

Times from the browser (`mutedUntil`, `remindAt`, sent as UTC "…Z" strings) are stored as UTC in `DATETIME` columns
(`Pvngu\Chat\Casts\UtcDatetime`) and read back on the app's clock, so they mean the same instant whatever
`config('app.timezone')` is, the night the clocks go back included. Installs that published the migrations before
0.1.0 have `timestamp` columns holding the app's wall time: change them to `dateTime` and convert existing values to
UTC (nothing to do in an app on UTC).

## Membership lines

When someone joins (by posting in a public channel), leaves, or is added or removed through the API, the chat gets a
line saying so: a message with `event: { type, user }` (`joined`, `left`, `added`, `removed`; the sender did it, `user`
is who it happened to) and empty text. These lines don't count as unread, aren't the sidebar's preview, aren't sent to
`Chat::recipients()` and can't be edited, reacted to or replied to. From code, `Chat::addMember()` and
`Chat::removeMember()` (with an optional `by:`) do the same; `addParticipant()` and `removeParticipant()` stay silent,
e.g. for seeding or creating a group.

## Admins and settings

Groups and channels have admins (their creator to start with) and members; each member's `role` comes with
`participants` / `members`. Admins:

- change a channel's name, description and privacy, or a group's name (changes are broadcast as
  `.conversation.updated`);
- remove people, and make people admins or members again (`PATCH {kind}/{id}/members/{userId}` with `{ role }`,
  `Chat::setRole()`); a chat always keeps one admin;
- are the only ones who add people to a **private channel** (in groups and public channels any member can).

The creator is the chat's owner: always an admin while they're in it (again if they come back), and other admins can't
remove them or change their role.

When the last admin leaves, the member they choose takes over (`DELETE {kind}/{id}/members/me` with
`{ successorId }`), or else whoever has been in it longest; the chat says so ("… made … an admin"). Everyone sees
`isAdmin`, `createdAt` and `createdBy` on conversations and channels; direct messages have no admins or settings.

## Reminders

Anyone can ask to be reminded of a message: `PUT messages/{id}/reminder` with `{ remindAt }` (or `Chat::remind()`),
one per message, a new time replacing the old one. `GET reminders` lists yours still to come, and
`DELETE messages/{id}/reminder` removes one.

At that time a queued job (delayed until then) fires `Pvngu\Chat\Events\ReminderDue` with the reminder, its
`message` and `user`. The package doesn't notify anyone itself: listen for it and send a notification, an email or a
push. A reminder whose message was deleted, or whose chat they can't see anymore, is dropped instead. Reminders need a
queue worker; if your queue driver can't delay jobs that long (SQS stops at 15 minutes) or jobs may get lost, schedule
the catch-up command too:

```php
// routes/console.php
Schedule::command('chat:send-reminders')->everyMinute();
```

## Saved messages

Anyone can save a message for later: `PUT messages/{id}/saved` (or `Chat::saveMessage()`), and `DELETE` to unsave it.
`GET saved` lists yours, newest first (`laravel-chat.pagination.saved`, 200 by default), each with the message as chats
show it, its chat (`kind`, `id`, `name`, `isDirect`) and `savedAt`. Deleted messages, and those of chats you can't see
anymore, drop out.

## Threads

Any message in a chat can have a thread of replies: `POST messages/{id}/replies` (same fields as a message, or
`Chat::send(..., thread: $message)`), and `GET messages/{id}/replies` pages through them like a chat's messages.
Replies stay out of the chat itself: not in its messages, its unread count or its latest message. The message they hang
off says `replyCount` and `lastReplyAt`, and is broadcast again (`.message.updated`) when they change; each reply is
broadcast as `.message.sent` with its `threadId`.

A reply sent with `alsoSendToChat: true` (`Chat::send(..., thread: $message, alsoInChat: true)`) shows in the chat
too, counting there like any message: it says `alsoInChat` and comes with `threadRoot` (`messageId`, `senderName`,
`text`) so the chat can show what it replies to.

Whoever started a thread, replied to it or is mentioned in it follows it: `GET threads` lists the threads you follow,
latest reply first, with each one's `unreadCount` and `latestReplies` (up to `pagination.thread_replies`, five by default, oldest first;
`POST messages/{id}/thread/read` when you've seen them). Only followers, and anyone a reply mentions, are among
`Chat::recipients()` for a reply that stays in its thread.

Upgrading from an earlier version: run `php artisan chat:install` again (or publish `laravel-chat-migrations`) and keep
only the migrations you don't have yet (`create_chat_reminders_table`, `create_chat_saved_messages_table`,
`update_chat_messages_for_threads`, `update_chat_messages_for_threads_in_chat`), then
`php artisan migrate`.

## GIFs

Messages can carry a GIF picked in vue-chat's GIF picker (`createKlipyGifs()`). The API accepts `gif` (`provider`,
`id`, `title`, `width`, `height`, `video`, `image`, `still`) and returns it on the message. GIFs are stored as their
provider's URLs, and browsers load them from the provider, as KLIPY requires.

Only HTTPS URLs on `gifs.media_hosts` are accepted (KLIPY's media servers by default), so messages can't embed images
from anywhere else; an empty list turns GIF messages off. Forwarding a message brings its GIF along, and previews and
reply quotes call it "GIF". From code: `Chat::send($conversation, $user, '', gif: [...])`.

## Events and real-time

`MessageSent`, `MessageUpdated` (edits, reactions, pins), `MessageDeleted`, `ConversationJoined` and
`ConversationUpdated` (settings) are ordinary
Laravel events, so you can listen to them (e.g. to email people who are offline). They wait for the database commit,
and when `broadcasting.enabled` is on they're broadcast too:

| Channel | Events |
| --- | --- |
| `private-chat.conversation.{id}` | `.message.sent`, `.message.updated`, `.message.deleted`, `.conversation.updated` |
| `private-chat.user.{publicId}` | `.conversation.joined` (added to a chat, a conversation started with them (with its first message in it), or a new message brought back one they had archived) |
| `presence-chat.presence.{key}` | joining/leaving, `.presence.changed` |

The package registers who may listen (members of the conversation, anyone in the tenant for a public channel, and
each person on their own user channel). Your app needs broadcasting set up (`php artisan install:broadcasting`) so the
channel authorization route exists. A conversation started with its first message (`POST conversations` with a
`body`, `Chat::start()`) also reaches the starter's other tabs and devices. Pass Echo's socket id to the adapter (as above) so your own changes aren't echoed
back to you.

## Changing the rules

Register your own policies for `Pvngu\Chat\Models\Conversation` (`view`, `send`, `participate`, `addMembers`,
`removeMember`, `update`, `manageRoles`) or `Pvngu\Chat\Models\Message` (`update`, `delete`, `react`, `pin`). App
service providers boot after packages, so yours replace the defaults.

## API

All under `/api/chat` (configurable). Lists are Laravel resource collections; paginated ones have `links.next`.
`{kind}` is `conversations` or `channels`.

| Method | Path | |
| --- | --- | --- |
| GET | `conversations?page=&search=` | Your direct and group conversations, latest activity first. `search` keeps the ones whose name, or someone else in them, contains it (any case); vue-chat's sidebar sends it with `conversationSearch="remote"` |
| GET | `conversations/lookup?participantIds[]=&isDirect=&name=` | What these people already have: their direct message (archived too), or `data: null` (a group is always new). Validates like `POST conversations` (422 for someone you can't message) and changes nothing |
| POST | `conversations` | `{ participantIds, isDirect, name? }`, optionally with a first message `{ body, attachments[], gif }` (multipart with files): both are saved together and the response is `{ data, firstMessage, created }` (201 new, 200 the existing direct message, which gets the message). Without a message it returns the existing direct message when there is one |
| POST | `conversations/{id}/pin`, `/archive` | `{ pinned }`, `{ archived }` |
| GET / POST | `channels` | Your channels plus the public ones; create `{ name, isPrivate, description? }` |
| GET | `{kind}/{id}` | One conversation or channel |
| PATCH | `{kind}/{id}` | Admins: `{ name?, description?, isPrivate? }` (description and privacy for channels) |
| POST | `{kind}/{id}/notifications` | `{ level: 'all' \| 'mentions' \| 'none', mutedUntil? }` |
| GET | `{kind}/{id}/messages?page=` | Page 1 is the latest messages; each page lists them oldest first |
| POST | `{kind}/{id}/messages` | `{ body, replyToId?, forwardedFromId?, attachments[]?, gif? }` (multipart with files) |
| GET | `{kind}/{id}/files?type=&before=` | Its files, newest first: `media` (photos and videos the browser shows) or `files`; `before` is the last id shown |
| POST | `{kind}/{id}/read`, `/favorite` | Mark read; `{ favorite }` |
| POST / DELETE | `{kind}/{id}/members`, `/members/{userId}`, `/members/me` | Add `{ userId }`, remove, leave `{ successorId? }` |
| PATCH | `{kind}/{id}/members/{userId}` | Admins: `{ role: 'admin' \| 'member' }` |
| PATCH / DELETE | `messages/{id}` | Edit `{ body }`; delete |
| POST / DELETE | `messages/{id}/reactions`, `/reactions/{emoji}` | `{ emoji }` |
| POST | `messages/{id}/pin` | `{ pinned }` |
| PUT / DELETE | `messages/{id}/reminder` | Remind you: `{ remindAt }` (a time to come, within a year); remove it |
| GET | `reminders` | Your reminders still to come: `{ messageId, remindAt, chat: { kind, id } }` |
| PUT / DELETE | `messages/{id}/saved` | Save a message for later; unsave it |
| GET | `saved` | Your saved messages, newest first: `{ message, chat: { kind, id, name, isDirect }, savedAt }` |
| GET / POST | `messages/{id}/replies?page=` | A thread's replies (page 1 is the latest); reply like a message, `alsoSendToChat?` |
| POST | `messages/{id}/thread/read` | You've seen the thread's replies |
| GET | `threads` | Threads you follow, latest reply first: `{ message, chat, unreadCount, latestReplies }` |
| GET | `users` | People you can start a conversation with |
| GET | `features` | What the chat can do here: `{ data: { channels, groups, threads, … } }` (see Features) |
| GET | `unread` | What your sidebar's badges add up to: `{ data: { chats, mentions } }` (see Unread totals) |
| GET | `presence?ids=` | Your presence channel, and who of these people is online or away |
| POST | `presence` | Heartbeat: `{ status: 'online' \| 'away' }` (any chat request also counts as being around) |

Errors are Laravel's usual JSON: 422 with `errors` for validation, 403 or 404 for permissions.

Attachments are served through their expiring links. When those are the package's own signed route (a disk without
temporary URLs), it's your app's site, so only plain images, video and audio (`Attachment::INLINE_TYPES`) show in
the browser; anything else, HTML, SVG and PDF included, downloads (`application/octet-stream`, `nosniff`, a sandboxing
`Content-Security-Policy`), so a file someone uploads can never run as a page of your app. SVGs are files, not images
(`isImage: false`).

## Development

```bash
composer install
composer test      # PHPUnit on Orchestra Testbench, SQLite in memory
composer lint      # Pint
```

CI runs the tests on PHP 8.2–8.4 with Laravel 12 and 13, at the lowest and latest dependency versions.

## License

MIT
