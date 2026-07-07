using Markdig;

namespace WishNet.Client.Services;

// Renders wish descriptions written in markdown. Raw HTML is disabled, so user text can never
// inject markup (only markdown formatting like **bold** / *italic* is honored).
public static class MarkdownRenderer
{
    private static readonly MarkdownPipeline Pipeline = new MarkdownPipelineBuilder().DisableHtml().Build();

    // Returns inline HTML: markdown is block-rendered then a single wrapping <p> is unwrapped so
    // the text sits inline within a list item.
    public static string ToInlineHtml(string? text)
    {
        string html = Markdown.ToHtml(text ?? string.Empty, Pipeline).Trim();
        if (html.StartsWith("<p>") && html.EndsWith("</p>"))
        {
            html = html[3..^4];
        }
        return html;
    }
}
