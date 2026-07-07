using System;
using System.Net.Http;
using Microsoft.AspNetCore.Components.Authorization;
using Microsoft.AspNetCore.Components.Web;
using Microsoft.AspNetCore.Components.WebAssembly.Hosting;
using Microsoft.Extensions.DependencyInjection;
using WishNet.Client;
using WishNet.Client.Services;

WebAssemblyHostBuilder builder = WebAssemblyHostBuilder.CreateDefault(args);
builder.RootComponents.Add<App>("#app");
builder.RootComponents.Add<HeadOutlet>("head::after");

// API base URL: a configured value wins (e.g. local XAMPP in appsettings.Development.json);
// otherwise derive from <base href> so the same-origin "<app>/api/" path works in production.
string? configuredApiBaseUrl = builder.Configuration["ApiBaseUrl"];
string apiBaseUrl = string.IsNullOrEmpty(configuredApiBaseUrl)
    ? $"{builder.HostEnvironment.BaseAddress}api/"
    : configuredApiBaseUrl;

builder.Services.AddScoped(sp => new HttpClient { BaseAddress = new Uri(apiBaseUrl) });
builder.Services.AddScoped<LocalStorage>();
builder.Services.AddScoped<TokenStore>();
builder.Services.AddScoped<ApiClient>();

builder.Services.AddAuthorizationCore();
builder.Services.AddScoped<ApiAuthStateProvider>();
builder.Services.AddScoped<AuthenticationStateProvider>(sp => sp.GetRequiredService<ApiAuthStateProvider>());

await builder.Build().RunAsync();
