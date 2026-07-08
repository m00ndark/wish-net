using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Threading.Tasks;
using WishNet.Client.Models;

namespace WishNet.Client.Services;

// Typed wrapper over the API. Attaches the bearer token per request (simpler than a
// DelegatingHandler in standalone WASM) and translates error responses into ApiException.
public sealed class ApiClient
{
    private readonly HttpClient _http;
    private readonly TokenStore _store;

    public ApiClient(HttpClient http, TokenStore store)
    {
        _http = http;
        _store = store;
    }

    // --- auth ---
    public Task<List<UserSummary>?> GetUsersAsync() => SendAsync<List<UserSummary>>(HttpMethod.Get, "users", null, authorize: false);

    public Task<AuthResult?> LoginAsync(int userId, string password) =>
        SendAsync<AuthResult>(HttpMethod.Post, "auth/login", new { userId, password }, authorize: false);

    public Task<AuthResult?> RegisterAsync(string userName, string password) =>
        SendAsync<AuthResult>(HttpMethod.Post, "auth/register", new { userName, password }, authorize: false);

    public Task LogoutAsync() => SendAsync(HttpMethod.Post, "auth/logout", null);

    public Task RecoverAsync(int userId) => SendAsync(HttpMethod.Post, "auth/recover", new { userId }, authorize: false);

    public Task ResetAsync(int userId, string code, string password) =>
        SendAsync(HttpMethod.Post, "auth/reset", new { userId, code, password }, authorize: false);

    // --- reference / lists ---
    public Task<List<CategoryDto>?> GetCategoriesAsync() => SendAsync<List<CategoryDto>>(HttpMethod.Get, "categories", null);

    public Task<HomeLists?> GetListsAsync() => SendAsync<HomeLists>(HttpMethod.Get, "lists", null);

    public Task<ListDetail?> GetListAsync(int id) => SendAsync<ListDetail>(HttpMethod.Get, $"lists/{id}", null);

    public Task<ListSummary?> CreateListAsync(string title, int? sharedWithUserId, bool isChildList, string childName) =>
        SendAsync<ListSummary>(HttpMethod.Post, "lists", new { title, sharedWithUserId, isChildList, childName });

    public Task<ListSummary?> UpdateListAsync(int id, string title, int? sharedWithUserId, bool isChildList, string childName) =>
        SendAsync<ListSummary>(HttpMethod.Put, $"lists/{id}", new { title, sharedWithUserId, isChildList, childName });

    public Task DeleteListAsync(int id) => SendAsync(HttpMethod.Delete, $"lists/{id}", null);

    public Task LockListAsync(int id, string lockDate) => SendAsync(HttpMethod.Post, $"lists/{id}/lock", new { lockDate });

    // --- wishes ---
    public Task<WishDto?> AddWishAsync(int listId, int categoryId, string description, string link, int maxReservationCount) =>
        SendAsync<WishDto>(HttpMethod.Post, "wishes", new { listId, categoryId, description, link, maxReservationCount });

    public Task<WishDto?> UpdateWishAsync(int id, int categoryId, string description, string link, int maxReservationCount) =>
        SendAsync<WishDto>(HttpMethod.Put, $"wishes/{id}", new { categoryId, description, link, maxReservationCount });

    public Task DeleteWishAsync(int id) => SendAsync(HttpMethod.Delete, $"wishes/{id}", null);

    public Task ReserveWishAsync(int id, int count) => SendAsync(HttpMethod.Post, $"wishes/{id}/reserve", new { count });

    // --- helpers ---
    private async Task<T?> SendAsync<T>(HttpMethod method, string path, object? body, bool authorize = true)
    {
        HttpResponseMessage response = await SendCoreAsync(method, path, body, authorize);
        await EnsureSuccessAsync(response);
        return await response.Content.ReadFromJsonAsync<T>();
    }

    private async Task SendAsync(HttpMethod method, string path, object? body, bool authorize = true)
    {
        HttpResponseMessage response = await SendCoreAsync(method, path, body, authorize);
        await EnsureSuccessAsync(response);
    }

    private async Task<HttpResponseMessage> SendCoreAsync(HttpMethod method, string path, object? body, bool authorize)
    {
        HttpRequestMessage request = new(method, path);
        if (body is not null)
        {
            request.Content = JsonContent.Create(body);
        }
        if (authorize)
        {
            string? token = await _store.GetTokenAsync();
            if (!string.IsNullOrEmpty(token))
            {
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
            }
        }
        return await _http.SendAsync(request);
    }

    private static async Task EnsureSuccessAsync(HttpResponseMessage response)
    {
        if (response.IsSuccessStatusCode)
        {
            return;
        }
        string message = "Ett fel uppstod.";
        try
        {
            ErrorResponse? error = await response.Content.ReadFromJsonAsync<ErrorResponse>();
            if (!string.IsNullOrEmpty(error?.Error))
            {
                message = error.Error;
            }
        }
        catch
        {
            // response had no JSON error body; keep the generic message
        }
        throw new ApiException((int) response.StatusCode, message);
    }

    private sealed record ErrorResponse(string? Error);
}
