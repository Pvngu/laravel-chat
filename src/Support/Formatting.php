<?php

namespace Pvngu\Chat\Support;

// Message formatting, as vue-chat writes it: **bold**, _italic_, __underline__, ~~strikethrough~~, `code`,
// [text](https://link), "> " quotes, "- " and "1. " lists and ``` code blocks. Underscores open before a word and close
// after one, so snake_case_names stay as they are; ** and ~~ work inside words too.
final class Formatting
{
    private const WORD = '[\p{L}\p{N}]';

    // The text without its markers, for previews and notifications (lists keep their "- " and "1. ")
    public static function plain(string $text): string
    {
        if (! preg_match('/[*_~`\[>]/', $text)) {
            return $text;
        }

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $fenced = str_starts_with(ltrim($line), '```') || str_ends_with(rtrim($line), '```');
            if (str_starts_with(ltrim($line), '```')) {
                $line = substr(ltrim($line), 3);
            }
            if (str_ends_with(rtrim($line), '```')) {
                $line = substr(rtrim($line), 0, -3);
            }
            if ($fenced && trim($line) === '') {
                continue;
            }
            $lines[] = (string) preg_replace('/^(?:>\s?)+/', '', $line);
        }

        return self::inline(implode("\n", $lines));
    }

    private static function inline(string $text): string
    {
        // Links show their text; code and bare addresses are kept as they are, markers and all
        $text = (string) preg_replace('/\[([^\]\n]+)\]\((?:https?:\/\/|mailto:)[^\s()]+\)/', '$1', $text);
        $kept = [];
        $text = (string) preg_replace_callback('/`([^`\n]+)`|https?:\/\/[^\s<>]+/', function (array $match) use (&$kept) {
            $kept[] = isset($match[1]) && $match[1] !== '' ? $match[1] : $match[0];

            return "\u{0}".(count($kept) - 1)."\u{0}";
        }, $text);

        // ** and ~~ work inside words too; underscores only at their edges
        foreach (['\*\*', '~~'] as $marker) {
            $text = (string) preg_replace("/$marker(?=\S)(.+?)(?<=\S)$marker/u", '$1', $text);
        }
        $word = self::WORD;
        $text = (string) preg_replace("/(?<!$word)__(?=\S)(.+?)(?<=\S)__(?!$word)/u", '$1', $text);
        $text = (string) preg_replace("/(?<![\p{L}\p{N}_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\p{L}\p{N}_])/u", '$1', $text);

        return (string) preg_replace_callback("/\u{0}(\d+)\u{0}/", fn (array $match) => $kept[(int) $match[1]], $text);
    }
}
