using System;

namespace WishNet.Client.Services;

// Thrown when the API returns a non-success status; Message carries the API's error text.
public sealed class ApiException : Exception
{
    public int StatusCode { get; }

    public ApiException(int statusCode, string message) : base(message)
    {
        StatusCode = statusCode;
    }
}
