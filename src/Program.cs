using Microsoft.AspNetCore.Components.Web;
using Microsoft.AspNetCore.Components.WebAssembly.Hosting;
using WishNet.Client;

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

await builder.Build().RunAsync();
