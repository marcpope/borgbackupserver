<?php

namespace BBS\Services;

/**
 * Release notes from the GitHub API, rendered for the Updates page.
 *
 * Raw HTML is kept. GitHub's editor writes `<img width=… src=…>` when an image
 * is pasted into a release body, so stripping HTML lost every screenshot. The
 * notes are written by the maintainer of this project and fetched from its own
 * releases, so the content is first-party.
 */
class ReleaseNotes
{
    public function render(string $markdown, string $issueBaseUrl = 'https://github.com/marcpope/borgbackupserver/issues/'): string
    {
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'allow']);
        $html = (string) $converter->convert($markdown);

        $html = $this->linkifyIssues($html, $issueBaseUrl);
        $html = $this->linkifyGitHubRefs($html, $issueBaseUrl);
        $html = $this->shortenIssueUrls($html, $issueBaseUrl);
        $html = $this->openLinksInNewTab($html);

        return $this->constrainImages($html);
    }

    /**
     * "#424" becomes a link, as it would on GitHub.
     *
     * Runs on the converted HTML and skips anchors, code and pre, so a hash
     * inside a URL, or a colour in `#fff`, is left as it is.
     */
    private function linkifyIssues(string $html, string $base): string
    {
        return (string) preg_replace_callback(
            '~(<a\b[^>]*>.*?</a>|<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>)|(^|[\s(\[])#(\d+)\b~is',
            function ($m) use ($base) {
                if ($m[1] !== '') {
                    return $m[1];
                }
                return $m[2] . '<a href="' . htmlspecialchars($base . $m[3], ENT_QUOTES) . '">#' . $m[3] . '</a>';
            },
            $html
        );
    }

    /**
     * The rest of what GitHub links on its own: @mentions, commit hashes, and
     * the GH-123 and owner/repo#123 forms. Bare URLs and raw <a> tags already
     * arrive as links, so they are not touched here.
     */
    private function linkifyGitHubRefs(string $html, string $issueBase): string
    {
        $repoBase = preg_replace('~/issues/$~', '', $issueBase);

        return (string) preg_replace_callback(
            '~(<a\b[^>]*>.*?</a>|<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>)'
            . '|(^|[\s(\[])(?:'
                . '@([A-Za-z0-9][A-Za-z0-9-]{0,38})'                    // @user
                . '|GH-(\d+)'                                           // GH-123
                . '|([A-Za-z0-9._-]+/[A-Za-z0-9._-]+)#(\d+)'            // owner/repo#123
                . '|([0-9a-f]{7,40})'                                   // commit hash
            . ')\b~is',
            function ($m) use ($issueBase, $repoBase) {
                if ($m[1] !== '') {
                    return $m[1];
                }
                $lead = $m[2];

                if (!empty($m[3])) {
                    return $lead . '<a href="https://github.com/' . htmlspecialchars($m[3], ENT_QUOTES) . '">@' . $m[3] . '</a>';
                }
                if (!empty($m[4])) {
                    return $lead . '<a href="' . htmlspecialchars($issueBase . $m[4], ENT_QUOTES) . '">GH-' . $m[4] . '</a>';
                }
                if (!empty($m[5]) && !empty($m[6])) {
                    $url = 'https://github.com/' . $m[5] . '/issues/' . $m[6];
                    return $lead . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $m[5] . '#' . $m[6] . '</a>';
                }
                if (!empty($m[7])) {
                    // A run of hex letters with no digit is a word — defaced,
                    // decade, effaced — not a commit.
                    if (!preg_match('~[0-9]~', $m[7])) {
                        return $m[0];
                    }
                    return $lead . '<a href="' . htmlspecialchars($repoBase . '/commit/' . $m[7], ENT_QUOTES) . '"><code>'
                        . substr($m[7], 0, 7) . '</code></a>';
                }
                return $m[0];
            },
            $html
        );
    }

    /**
     * A full link to an issue in this repository is shown as #123, the way
     * GitHub renders one pasted into a body.
     */
    private function shortenIssueUrls(string $html, string $issueBase): string
    {
        $quoted = preg_quote($issueBase, '~');
        return (string) preg_replace(
            '~<a href="(' . $quoted . '(\d+))"([^>]*)>\1</a>~i',
            '<a href="$1"$3>#$2</a>',
            $html
        );
    }

    /** Every link here points off the install; keep the admin page where it is. */
    private function openLinksInNewTab(string $html): string
    {
        return (string) preg_replace_callback(
            '~<a\b([^>]*)>~i',
            function ($m) {
                $attrs = $m[1];
                if (preg_match('~\btarget\s*=~i', $attrs)) {
                    return $m[0];
                }
                return '<a' . $attrs . ' target="_blank" rel="noopener">';
            },
            $html
        );
    }

    /**
     * GitHub records the original pixel size on a pasted image, which is wider
     * than the panel. Drop those and let the stylesheet size it.
     */
    private function constrainImages(string $html): string
    {
        return (string) preg_replace_callback(
            '~<img\b([^>]*)>~i',
            function ($m) {
                $attrs = preg_replace('~\s*\b(width|height|class)\s*=\s*("[^"]*"|\'[^\']*\'|\S+)~i', '', $m[1]);
                // GitHub writes these self-closed. Without dropping the slash
                // the rebuilt tag reads `src="…" / class="…"`.
                $attrs = rtrim(rtrim($attrs), '/');
                return '<img' . rtrim($attrs) . ' class="release-notes-img" loading="lazy">';
            },
            $html
        );
    }
}
