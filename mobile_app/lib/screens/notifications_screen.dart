import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../widgets/common.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<AppNotification> _items = [];
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; });
    try {
      final list = await SessionStore.api.get('/notifications?limit=50') as List;
      setState(() {
        _items = list.map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e))).toList();
        _error = null;
        _loading = false;
      });
    } catch (e) {
      setState(() { _error = 'Could not load notifications.'; _loading = false; });
    }
  }

  Future<void> _markRead(AppNotification n) async {
    if (n.isRead) return;
    try {
      await SessionStore.api.patch('/notifications/${n.id}', {});
      _load();
    } catch (_) {}
  }

  Future<void> _markAll() async {
    try {
      await SessionStore.api.post('/notifications/read-all');
      _load();
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications'), actions: [
        TextButton(onPressed: _markAll, child: const Text('Mark all read', style: TextStyle(color: Colors.white))),
      ]),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? ErrorBox(_error!, onRetry: _load)
              : _items.isEmpty
                  ? const EmptyState(Icons.notifications_none, 'No notifications yet.')
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView.builder(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(12),
                        itemCount: _items.length,
                        itemBuilder: (context, i) {
                          final n = _items[i];
                          return Card(
                            margin: const EdgeInsets.only(bottom: 8),
                            child: ListTile(
                              onTap: () => _markRead(n),
                              leading: CircleAvatar(
                                backgroundColor: n.isRead ? kMuted.withOpacity(.12) : kTeal.withOpacity(.15),
                                child: Icon(n.isRead ? Icons.notifications_none : Icons.notifications_active, size: 20, color: n.isRead ? kMuted : kTeal),
                              ),
                              title: Text(n.title, style: TextStyle(fontSize: 13.5, fontWeight: n.isRead ? FontWeight.w500 : FontWeight.w800)),
                              subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text(n.message, style: const TextStyle(fontSize: 12.5)),
                                const SizedBox(height: 3),
                                Text(dmyTime(n.createdAt), style: const TextStyle(fontSize: 10.5, color: kMuted)),
                              ]),
                            ),
                          );
                        },
                      ),
                    ),
    );
  }
}
