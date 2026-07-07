using System.Security.Claims;
using System.Threading.Tasks;
using Microsoft.AspNetCore.Components.Authorization;
using WishNet.Client.Models;

namespace WishNet.Client.Services;

// Exposes the stored session to Blazor's authorization system. Login/logout call the Notify*
// methods so [Authorize] pages and AuthorizeView react immediately.
public sealed class ApiAuthStateProvider : AuthenticationStateProvider
{
    private readonly TokenStore _store;

    public ApiAuthStateProvider(TokenStore store)
    {
        _store = store;
    }

    public override async Task<AuthenticationState> GetAuthenticationStateAsync()
    {
        string? token = await _store.GetTokenAsync();
        UserDto? user = await _store.GetUserAsync();
        return string.IsNullOrEmpty(token) || user is null ? Anonymous() : new AuthenticationState(BuildPrincipal(user));
    }

    public void NotifyLogin(UserDto user)
    {
        NotifyAuthenticationStateChanged(Task.FromResult(new AuthenticationState(BuildPrincipal(user))));
    }

    public void NotifyLogout()
    {
        NotifyAuthenticationStateChanged(Task.FromResult(Anonymous()));
    }

    private static AuthenticationState Anonymous()
    {
        return new AuthenticationState(new ClaimsPrincipal(new ClaimsIdentity()));
    }

    private static ClaimsPrincipal BuildPrincipal(UserDto user)
    {
        List<Claim> claims =
        [
            new Claim(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new Claim(ClaimTypes.Name, user.UserName),
        ];
        if (user.IsSuper)
        {
            claims.Add(new Claim("super", "true"));
        }
        return new ClaimsPrincipal(new ClaimsIdentity(claims, "apiauth"));
    }
}
