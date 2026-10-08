import 'package:flutter/material.dart';

/// Electricity-themed Material 3 design: deep navy navigation,
/// electric teal actions, green/amber/red state colours.
final semsTheme = ThemeData(
  useMaterial3: true,
  brightness: Brightness.light,
  colorScheme: ColorScheme.fromSeed(
    seedColor: const Color(0xFF0EA5B7),
    primary: const Color(0xFF0D1B2E),
    secondary: const Color(0xFF0EA5B7),
    surface: Colors.white,
  ),
  scaffoldBackgroundColor: const Color(0xFFF2F5F9),
  appBarTheme: const AppBarTheme(
    backgroundColor: Color(0xFF0D1B2E),
    foregroundColor: Colors.white,
    elevation: 0,
    centerTitle: false,
  ),
  navigationBarTheme: NavigationBarThemeData(
    backgroundColor: Colors.white,
    indicatorColor: const Color(0xFF0EA5B7).withOpacity(.18),
    labelTextStyle: WidgetStatePropertyAll(
      const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600),
    ),
  ),
  elevatedButtonTheme: ElevatedButtonThemeData(
    style: ElevatedButton.styleFrom(
      backgroundColor: const Color(0xFF0EA5B7),
      foregroundColor: Colors.white,
      minimumSize: const Size(double.infinity, 48),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
    ),
  ),
  outlinedButtonTheme: OutlinedButtonThemeData(
    style: OutlinedButton.styleFrom(
      minimumSize: const Size(double.infinity, 48),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
  ),
  inputDecorationTheme: InputDecorationTheme(
    filled: true,
    fillColor: Colors.white,
    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE3E8F0))),
    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFE3E8F0))),
    focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFF0EA5B7), width: 1.6)),
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
  ),
  cardTheme: CardThemeData(
    elevation: 0,
    color: Colors.white,
    shape: RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(16),
      side: const BorderSide(color: Color(0xFFE3E8F0)),
    ),
  ),
);

const kNavy = Color(0xFF0D1B2E);
const kTeal = Color(0xFF0EA5B7);
const kGreen = Color(0xFF16A34A);
const kAmber = Color(0xFFD97706);
const kRed = Color(0xFFDC2626);
const kMuted = Color(0xFF61708C);
const kLine = Color(0xFFE3E8F0);
