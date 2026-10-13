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
php artisan chat:install     # publishes config/laravel-chat.php and the migration
php artisan migrate
```

Check `config/laravel-chat.php` before migrating:

- `routes.middleware`: `['api', 'auth:sanctum']` by default. For a same-origin SPA with session login, `['web', 'auth']`
  also works.
- `user_key_type`: `'uuid'`, `'ulid'` or `'string'` if your users table key isn't a big integer.
- `user_attributes`: which attributes hold the name, email and avatar, and `tenant` (e.g. `'company_id'`) for
  multi-tenant apps: people only see conversations and other people with the same value.
- `table_prefix`: tables are `chat_conversations`, `chat_participants`, `chat_messages`, `chat_attachments` and
  `chat_reactions`. [musonza/chat](https://github.com/musonza/chat) uses the same prefix; change one of them if both
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

The methods are `publicId()`, `find()`, `present()` (name, email, avatar), `tenant()` and `available()`; see
`Pvngu\Chat\Contracts\ChatUserProvider`.

## Using it from code

The `Chat` facade does everything the API does, without permission checks:

```php
use Pvngu\Chat\Facades\Chat;

$conversation = Chat::direct($user, $otherUser);            // the existing one, or a new one
$group = Chat::group($user, [$ann, $john], 'Launch');
$channel = Chat::channel($user, 'announcements');

Chat::send($channel, $bot, 'Deploy finished ✅');            // also accepts UploadedFile[] attachments
Chat::addParticipant($channel, $newHire);
Chat::addMember($channel, $newHire, by: $user);              // shows "… added …" in the chat (removeMember too)
Chat::update($channel, ['description' => 'Company news']);   // name, description, isPrivate (channels)
Chat::setNotifications($channel, $user, 'mentions');         // 'all' | 'mentions' | 'none', optional mute end
```

## Mentions and notifications

A mention is `<@publicId>` in a message's text (the id your `ChatUserProvider` gives the person), which vue-chat's
message box writes when someone picks a name after `@`. The package records who each message mentions (people in the
same tenant), returns them as `mentions: [{ id, name }]`, counts unread ones per chat (`mentionCount`), and shows them
as "@Name" in previews and reply quotes.

`<!everyone>` mentions everyone else in the chat and `<!here>` everyone else who's online, shown as "@everyone" and
"@here". They count as a mention for each of those people (unread counts, notifications) without being listed in
`mentions`, so a message to a large channel stays small.

`@here` reaches the people who are online, not away (see Presence).

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
so a closed tab drops out on its own; any chat request also counts as being around. If your app already tracks
presence, implement `Pvngu\Chat\Contracts\ChatPresence` (`touch()` and `statuses()`) and set `presence.driver`.

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

## Membership lines

When someone joins (by posting in a public channel), leaves, or is added or removed through the API, the chat gets a
line saying so: a message with `event: { type, user }` (`joined`, `left`, `added`, `removed`; the sender did it, `user`
is who it happened to) and empty text. These lines don't count as unread, aren't the sidebar's preview, aren't sent to
`Chat::recipients()` and can't be edited, reacted to or replied to. From code, `Chat::addMember()` and
`Chat::removeMember()` (with an optional `by:`) do the same; `addParticipant()` and `removeParticipant()` stay silent,
e.g. for seeding or creating a group.

## Settings

Admins of a channel (its creator, and anyone you make `admin`) can change its name, description and privacy; a
group's admins can rename it. Everyone sees `isAdmin`, `createdAt` and `createdBy` on conversations and channels.
Direct messages have no settings. Changes are broadcast as `.conversation.updated`.

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
| `private-chat.user.{publicId}` | `.conversation.joined` |
| `presence-chat.presence.{key}` | joining/leaving, `.presence.changed` |

The package registers who may listen (members of the conversation, anyone in the tenant for a public channel, and
each person on their own user channel). Your app needs broadcasting set up (`php artisan install:broadcasting`) so the
channel authorization route exists. Pass Echo's socket id to the adapter (as above) so your own changes aren't echoed
back to you.

## Changing the rules

Register your own policies for `Pvngu\Chat\Models\Conversation` (`view`, `send`, `participate`, `addMembers`,
`removeMember`, `update`) or `Pvngu\Chat\Models\Message` (`update`, `delete`, `react`, `pin`). App service providers boot after
packages, so yours replace the defaults.

## API

All under `/api/chat` (configurable). Lists are Laravel resource collections; paginated ones have `links.next`.
`{kind}` is `conversations` or `channels`.

| Method | Path | |
| --- | --- | --- |
| GET | `conversations?page=` | Your direct and group conversations, latest activity first |
| POST | `conversations` | `{ participantIds, isDirect, name? }`; returns the existing direct message when there is one |
| POST | `conversations/{id}/pin`, `/archive` | `{ pinned }`, `{ archived }` |
| GET / POST | `channels` | Your channels plus the public ones; create `{ name, isPrivate, description? }` |
| GET | `{kind}/{id}` | One conversation or channel |
| PATCH | `{kind}/{id}` | Admins: `{ name?, description?, isPrivate? }` (description and privacy for channels) |
| POST | `{kind}/{id}/notifications` | `{ level: 'all' \| 'mentions' \| 'none', mutedUntil? }` |
| GET | `{kind}/{id}/messages?page=` | Page 1 is the latest messages; each page lists them oldest first |
| POST | `{kind}/{id}/messages` | `{ body, replyToId?, forwardedFromId?, attachments[]?, gif? }` (multipart with files) |
| POST | `{kind}/{id}/read`, `/favorite` | Mark read; `{ favorite }` |
| POST / DELETE | `{kind}/{id}/members`, `/members/{userId}`, `/members/me` | Add `{ userId }`, remove, leave |
| PATCH / DELETE | `messages/{id}` | Edit `{ body }`; delete |
| POST / DELETE | `messages/{id}/reactions`, `/reactions/{emoji}` | `{ emoji }` |
| POST | `messages/{id}/pin` | `{ pinned }` |
| GET | `users` | People you can start a conversation with |
| GET | `presence?ids=` | Your presence channel, and who of these people is online or away |
| POST | `presence` | Heartbeat: `{ status: 'online' \| 'away' }` (any chat request also counts as being around) |

Errors are Laravel's usual JSON: 422 with `errors` for validation, 403 or 404 for permissions.

## Development

```bash
composer install
composer test      # PHPUnit on Orchestra Testbench, SQLite in memory
composer lint      # Pint
```

CI runs the tests on PHP 8.2–8.4 with Laravel 12 and 13, at the lowest and latest dependency versions.

## License

MIT
