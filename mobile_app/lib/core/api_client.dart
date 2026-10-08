import 'dart:convert';

import 'package:http/http.dart' as http;

/// REST client for the SEMS backend (/api/v1).
/// Configure [baseUrl] to point at your development machine, e.g.
///   const ApiClient(baseUrl: 'http://10.0.2.2:8000/api/v1')  // Android emulator
///   const ApiClient(baseUrl: 'http://192.168.1.10:8000/api/v1') // physical device
class ApiClient {
  ApiClient({required this.baseUrl, String? token}) : _token = token;

  final String baseUrl;
  String? _token;

  String? get token => _token;
  set token(String? t) => _token = t;

  Future<dynamic> get(String path) => _send('GET', path);
  Future<dynamic> post(String path, [Map<String, dynamic>? body]) => _send('POST', path, body);
  Future<dynamic> patch(String path, Map<String, dynamic> body) => _send('PATCH', path, body);

  Future<dynamic> _send(String method, String path, [Map<String, dynamic>? body]) async {
    final uri = Uri.parse('$baseUrl$path');
    final headers = <String, String>{'Accept': 'application/json'};
    if (body != null) headers['Content-Type'] = 'application/json';
    if (_token != null && _token!.isNotEmpty) headers['Authorization'] = 'Bearer $_token';

    final res = await http.Request(method, uri);
    res.headers.addAll(headers);
    if (body != null) res.body = jsonEncode(body);

    final streamed = await http.Client().send(res);
    final raw = await streamed.stream.bytesToString();
    dynamic json;
    try {
      json = jsonDecode(raw);
    } catch (_) {
      throw ApiException(streamed.statusCode, 'Server returned an invalid response');
    }

    if (streamed.statusCode >= 200 && streamed.statusCode < 300) {
      return json['data'];
    }
    final message = json?['error']?['message'] ?? 'Request failed (${streamed.statusCode})';
    final fields = (json?['error']?['fields'] as Map?)?.map((k, v) => MapEntry(k.toString(), v.toString()));
    throw ApiException(streamed.statusCode, message.toString(), fields: fields?.cast<String, String>());
  }
}

class ApiException implements Exception {
  ApiException(this.status, this.message, {this.fields});
  final int status;
  final String message;
  final Map<String, String>? fields;

  String get detailed {
    if (fields == null || fields!.isEmpty) return message;
    return '$message\n${fields!.entries.map((e) => '• ${e.key}: ${e.value}').join('\n')}';
  }

  @override
  String toString() => message;
}
