namespace WishNet.Client.Models;

// DTOs mirroring the PHP API's camelCase JSON (System.Text.Json web defaults are case-insensitive).

public sealed record UserDto(int Id, string UserName, bool IsSuper);

public sealed record UserSummary(int Id, string UserName);

public sealed record AuthResult(string Token, UserDto User);

public sealed record CategoryDto(int Id, string Name);

public sealed record ListSummary(
    int Id,
    string Title,
    int OwnerUserId,
    string OwnerName,
    int? SharedWithUserId,
    string? SharedWithName,
    bool IsChildList,
    string ChildName,
    bool IsLocked,
    string? LockedUntil,
    bool IsOwner,
    bool IsMine);

public sealed record ListRef(int Id, string Title);

public sealed record OthersGroup(string Name, List<ListRef> Lists);

public sealed record HomeLists(List<ListSummary> MyLists, List<OthersGroup> OthersLists);

public sealed record ReservedBy(string UserName, int Count);

public sealed record WishDto(
    int Id,
    string Description,
    string Link,
    int MaxReservationCount,
    int ReservationCount,
    bool IsReserved,
    bool IsFullyReserved,
    bool CanReserve,
    List<ReservedBy>? ReservedBy);

public sealed record CategoryWishes(int Id, string Name, List<WishDto> Wishes);

public sealed record ListDetail(
    int Id,
    string Title,
    int OwnerUserId,
    string OwnerName,
    int? SharedWithUserId,
    string? SharedWithName,
    bool IsChildList,
    string ChildName,
    bool IsLocked,
    string? LockedUntil,
    bool IsOwner,
    bool IsMine,
    bool CanAddWish,
    bool CanModifyWishes,
    List<CategoryWishes> Categories);
