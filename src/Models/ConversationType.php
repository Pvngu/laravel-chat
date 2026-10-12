<?php

namespace Pvngu\Chat\Models;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Channel = 'channel';
}
