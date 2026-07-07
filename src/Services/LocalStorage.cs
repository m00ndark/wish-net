using System.Threading.Tasks;
using Microsoft.JSInterop;

namespace WishNet.Client.Services;

// Thin wrapper over the browser's localStorage via JS interop (avoids an extra NuGet dependency).
public sealed class LocalStorage
{
    private readonly IJSRuntime _js;

    public LocalStorage(IJSRuntime js)
    {
        _js = js;
    }

    public ValueTask<string?> GetAsync(string key)
    {
        return _js.InvokeAsync<string?>("localStorage.getItem", key);
    }

    public ValueTask SetAsync(string key, string value)
    {
        return _js.InvokeVoidAsync("localStorage.setItem", key, value);
    }

    public ValueTask RemoveAsync(string key)
    {
        return _js.InvokeVoidAsync("localStorage.removeItem", key);
    }
}
