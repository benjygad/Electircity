import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'api_client.dart';

/// Holds the authenticated session. The bearer token is stored in the
/// platform's secure storage (Keystore on Android, Keychain on iOS) —
/// never in plain shared preferences.
class SessionStore extends ChangeNotifier {
  static const _storage = FlutterSecureStorage();
  static const _kToken = 'sems_token';
  static const _kUser = 'sems_user';

  /// Point this at your backend before building.
  static final ApiClient api = ApiClient(baseUrl: const String.fromEnvironment(
    'SEMS_API_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  ));

  Map<String, dynamic>? user;
  String? get userName => user?['full_name'];
  String? get email => user?['email'];

  String? get token => api.token;

  Future<void> load() async {
    final t = await _storage.read(key: _kToken);
    final u = await _storage.read(key: _kUser);
    if (t != null && u != null) {
      api.token = t;
      try {
        user = Map<String, dynamic>.from(jsonDecode(u));
        // re-validate against the server; drop session if expired/suspended
        final me = await api.get('/auth/me');
        user = Map<String, dynamic>.from(me);
      } catch (_) {
        await clear();
      }
    }
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final data = await api.post('/auth/login', {'email': email, 'password': password});
    await _applyAuth(data);
  }

  Future<void> register(Map<String, dynamic> payload) async {
    final data = await api.post('/auth/register', payload);
    await _applyAuth(data);
  }

  Future<void> _applyAuth(Map<String, dynamic> data) async {
    api.token = data['token'];
    user = Map<String, dynamic>.from(data['user']);
    await _storage.write(key: _kToken, value: data['token']);
    await _storage.write(key: _kUser, value: jsonEncode(data['user']));
    notifyListeners();
  }

  Future<void> refreshMe() async {
    try {
      user = Map<String, dynamic>.from(await api.get('/auth/me'));
      await _storage.write(key: _kUser, value: jsonEncode(user));
      notifyListeners();
    } catch (_) {/* keep cached profile when offline */}
  }

  Future<void> logout() async {
    try { await api.post('/auth/logout'); } catch (_) {}
    await clear();
  }

  Future<void> clear() async {
    api.token = null;
    user = null;
    await _storage.delete(key: _kToken);
    await _storage.delete(key: _kUser);
    notifyListeners();
  }
}
