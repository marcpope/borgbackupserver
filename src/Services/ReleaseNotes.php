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
