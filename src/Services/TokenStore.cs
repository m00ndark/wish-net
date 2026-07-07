using System.Text.Json;
using System.Threading.Tasks;
using WishNet.Client.Models;

namespace WishNet.Client.Services;

// Persists the bearer token, the current user, and the last-used user id in localStorage.
public sealed class TokenStore
{
    private const string TokenKey = "wishnet.token";
    private const string UserKey = "wishnet.user";
    private const string LastUserKey = "wishnet.lastUserId";

    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);

    private readonly LocalStorage _storage;

    public TokenStore(LocalStorage storage)
    {
        _storage = storage;
    }

    public ValueTask<string?> GetTokenAsync()
    {
        return _storage.GetAsync(TokenKey);
    }

    public async Task<UserDto?> GetUserAsync()
    {
        string? json = await _storage.GetAsync(UserKey);
        return string.IsNullOrEmpty(json) ? null : JsonSerializer.Deserialize<UserDto>(json, JsonOptions);
    }

    public async Task SetSessionAsync(string token, UserDto user)
    {
        await _storage.SetAsync(TokenKey, token);
        await _storage.SetAsync(UserKey, JsonSerializer.Serialize(user, JsonOptions));
        await _storage.SetAsync(LastUserKey, user.Id.ToString());
    }

    public async Task ClearAsync()
    {
        await _storage.RemoveAsync(TokenKey);
        await _storage.RemoveAsync(UserKey);
    }

    public async Task<int> GetLastUserIdAsync()
    {
        string? value = await _storage.GetAsync(LastUserKey);
        return int.TryParse(value, out int id) ? id : 0;
    }
}
