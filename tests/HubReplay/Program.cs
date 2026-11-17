using System.Net;
using System.Text.Json;
using Kandoo.Hub;

// Real, pinned Hub transport talking over HTTP to the PHP process, synthetic data only.
const string secret = "TEST-p23-synthetic-secret-0123456789abcdef";
var batch = JsonSerializer.Deserialize<ReplicationBatch>(File.ReadAllText(args[0]), HubJson.Options)
    ?? throw new Exception("Fixture batch missing");
HubReplicationPayloadV11.Apply(batch, new HubReplicationOptions {
    PayloadV11Enabled = true,
    PayloadV11StoreCurrencies = new Dictionary<Guid, string> { [batch.StoreId] = "toman" }
});
using var http = new HttpClient { BaseAddress = new Uri("http://127.0.0.1:18083") };
var transport = new HttpCloudReplicationTransport(http);
async Task Check(HttpStatusCode expected) {
    var response = await transport.SendAsync(batch, CancellationToken.None);
    if (response.HttpStatus != expected) throw new Exception($"Expected {(int)expected}, got {(int)response.HttpStatus}: {response.Body}");
    if (expected == HttpStatusCode.OK && (response.Body?.GetProperty("status").GetString() != "ACCEPTED" || response.Body?.GetProperty("accepted_through_seq").GetInt64() != 3))
        throw new Exception("Unexpected ingest result");
}
batch.DeviceSecret = null; await Check(HttpStatusCode.Unauthorized);
batch.DeviceSecret = "TEST-wrong-secret-0123456789abcdef"; await Check(HttpStatusCode.Unauthorized);
batch.DeviceSecret = secret;
var json = JsonSerializer.Serialize(batch, HubJson.Options);
if (json.Contains(secret, StringComparison.Ordinal) || json.Contains("device_secret", StringComparison.Ordinal)) throw new Exception("Secret leaked into body");
if (!json.Contains("\"payload_version\":\"1.1\"") || !json.Contains("\"invoice_schema_version\":\"1.1\"")) throw new Exception("Missing v1.1");
await Check(HttpStatusCode.OK);
Console.WriteLine("P-23 REAL HUB C-83: missing key 401; wrong key 401; Bearer key 200 ACCEPTED; v1.1 marker; secret absent from body.");
