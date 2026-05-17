import sqlite3
import random
import string
import asyncio
import requests
from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
from telegram.ext import Application, CommandHandler, CallbackQueryHandler, MessageHandler, filters, ContextTypes

TOKEN = '7656448308:AAEPkCNpBtiiw70r-pKuFPdWo6StZnBTeEE'

def init_db():
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS users (
                    user_id INTEGER PRIMARY KEY,
                    banned INTEGER DEFAULT 0
                )''')
    c.execute('''CREATE TABLE IF NOT EXISTS orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    volume TEXT,
                    duration TEXT,
                    tracking_code TEXT,
                    payment_status TEXT DEFAULT 'pending',
                    config TEXT,
                    screenshot TEXT
                )''')
    conn.commit()
    conn.close()

init_db()

def generate_tracking_code():
    return ''.join(random.choices(string.ascii_uppercase + string.digits, k=10))

SAMPLE_CONFIG = '''{
  "v": "2",
  "ps": "Sample Config",
  "add": "example.com",
  "port": "443",
  "id": "uuid-here",
  "aid": "0",
  "net": "ws",
  "type": "none",
  "host": "",
  "path": "/path",
  "tls": "tls"
}'''

ADMIN_PASSWORD = '1390'
ADMIN_USER_ID = None

def main_menu():
    keyboard = [
        [InlineKeyboardButton("📊 حجم کانفیگ", callback_data='volume')],
        [InlineKeyboardButton("⏰ مدت زمان", callback_data='duration')],
        [InlineKeyboardButton("✅ تایید و پرداخت", callback_data='confirm')]
    ]
    return InlineKeyboardMarkup(keyboard)

def volume_menu():
    keyboard = [
        [InlineKeyboardButton("10 GB 📦", callback_data='vol_10')],
        [InlineKeyboardButton("50 GB 📦", callback_data='vol_50')],
        [InlineKeyboardButton("100 GB 📦", callback_data='vol_100')],
        [InlineKeyboardButton("نامحدود ♾️", callback_data='vol_unlimited')]
    ]
    return InlineKeyboardMarkup(keyboard)

def duration_menu():
    keyboard = [
        [InlineKeyboardButton("1 هفته 📅", callback_data='dur_1week')],
        [InlineKeyboardButton("1 ماه 📅", callback_data='dur_1month')],
        [InlineKeyboardButton("1 سال 📅", callback_data='dur_1year')],
        [InlineKeyboardButton("دائمی ♾️", callback_data='dur_permanent')]
    ]
    return InlineKeyboardMarkup(keyboard)

def is_banned(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT banned FROM users WHERE user_id = ?', (user_id,))
    result = c.fetchone()
    conn.close()
    return result and result[0] == 1

def ban_user(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT OR REPLACE INTO users (user_id, banned) VALUES (?, 1)', (user_id,))
    conn.commit()
    conn.close()

def unban_user(user_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT OR REPLACE INTO users (user_id, banned) VALUES (?, 0)', (user_id,))
    conn.commit()
    conn.close()

def save_order(user_id, volume, duration):
    tracking_code = generate_tracking_code()
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('INSERT INTO orders (user_id, volume, duration, tracking_code) VALUES (?, ?, ?, ?)',
              (user_id, volume, duration, tracking_code))
    conn.commit()
    conn.close()
    return tracking_code

def get_orders():
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT id, user_id, volume, duration, tracking_code, payment_status FROM orders')
    orders = c.fetchall()
    conn.close()
    return orders

def update_order(order_id, status, config=None):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    if config:
        c.execute('UPDATE orders SET payment_status = ?, config = ? WHERE id = ?', (status, config, order_id))
    else:
        c.execute('UPDATE orders SET payment_status = ? WHERE id = ?', (status, order_id))
    conn.commit()
    conn.close()

def get_order_by_tracking(tracking_code):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT * FROM orders WHERE tracking_code = ?', (tracking_code,))
    order = c.fetchone()
    conn.close()
    return order

def get_order_by_id(order_id):
    conn = sqlite3.connect('bot.db')
    c = conn.cursor()
    c.execute('SELECT * FROM orders WHERE id = ?', (order_id,))
    order = c.fetchone()
    conn.close()
    return order

async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if is_banned(user_id):
        await update.message.reply_text("🚫 شما بن شده‌اید.")
        return
    await update.message.reply_text("🌟 خوش آمدید!\n\n📊 حجم و مدت زمان را انتخاب کنید:", reply_markup=main_menu())

async def button_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query
    await query.answer()
    user_id = query.from_user.id
    if is_banned(user_id):
        await query.edit_message_text("🚫 شما بن شده‌اید.")
        return

    data = query.data
    if data == 'volume':
        await query.edit_message_text("📊 حجم را انتخاب کنید:", reply_markup=volume_menu())
    elif data == 'duration':
        await query.edit_message_text("⏰ مدت زمان را انتخاب کنید:", reply_markup=duration_menu())
    elif data.startswith('vol_'):
        volume = data.split('_')[1]
        context.user_data['volume'] = volume
        await query.edit_message_text(f"📦 حجم: {volume} GB", reply_markup=duration_menu())
    elif data.startswith('dur_'):
        duration = data.split('_')[1]
        context.user_data['duration'] = duration
        await query.edit_message_text(f"⏳ مدت زمان: {duration}", reply_markup=main_menu())
    elif data == 'confirm':
        volume = context.user_data.get('volume')
        duration = context.user_data.get('duration')
        if not volume or not duration:
            await query.edit_message_text("⚠️ حجم و مدت زمان را انتخاب کنید.", reply_markup=main_menu())
            return
        tracking_code = save_order(user_id, volume, duration)
        card_number = "425645663868689768786"
        await query.edit_message_text(f"✅ ثبت شد!\n🔢 کد: `{tracking_code}`\n💳 واریز به: `{card_number}`\nاسکرین‌شات را ریپلای کنید.", parse_mode='Markdown')
    elif user_id == ADMIN_USER_ID:
        if data == 'admin_list':
            orders = get_orders()
            text = "📋 سفارشات:\n" + "\n".join([f"🆔 {o[0]} | 👤 {o[1]} | 📦 {o[2]} | ⏰ {o[3]} | 🔢 {o[4]} | 📊 {o[5]}" for o in orders]) if orders else "هیچ سفارشی نیست."
            await query.edit_message_text(text, reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
        elif data in ['admin_track', 'admin_ban', 'admin_unban', 'admin_approve', 'admin_reject']:
            prompts = {
                'admin_track': "🔍 کد پیگیری را وارد کنید:",
                'admin_ban': "🚫 ایدی کاربر را وارد کنید:",
                'admin_unban': "✅ ایدی کاربر:",
                'admin_approve': "✔️ ایدی سفارش:",
                'admin_reject': "❌ ایدی سفارش:"
            }
            await query.edit_message_text(prompts[data], reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data[f'awaiting_{data.split("_")[1]}'] = True
        elif data == 'admin_back':
            await show_admin_panel(update, context, query)
    else:
        await query.edit_message_text("❌ دسترسی غیرمجاز.")

async def admin_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    global ADMIN_USER_ID
    if ADMIN_USER_ID == update.effective_user.id:
        await show_admin_panel(update, context)
    else:
        await update.message.reply_text("🔐 رمز عبور را وارد کنید:")

async def message_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    text = update.message.text
    global ADMIN_USER_ID

    if ADMIN_USER_ID == user_id:
        if context.user_data.get('awaiting_track'):
            order = get_order_by_tracking(text)
            await update.message.reply_text(f"📊 وضعیت: {order[5] if order else 'نامعتبر'}", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            context.user_data['awaiting_track'] = False
        elif context.user_data.get('awaiting_ban'):
            try:
                ban_user(int(text))
                await update.message.reply_text(f"🚫 بن شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            except:
                await update.message.reply_text("❌ نامعتبر.")
            context.user_data['awaiting_ban'] = False
        elif context.user_data.get('awaiting_unban'):
            try:
                unban_user(int(text))
                await update.message.reply_text(f"✅ انبن شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            except:
                await update.message.reply_text("❌ نامعتبر.")
            context.user_data['awaiting_unban'] = False
        elif context.user_data.get('awaiting_approve'):
            try:
                order_id = int(text)
                update_order(order_id, 'approved', SAMPLE_CONFIG)
                await update.message.reply_text(f"✔️ تایید شد.", reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back')]]))
            except:
                await update.message.reply_text("❌ نامعتبر.")
            context.user_data['awaiting_approve'] = False
        elif context.user_data.get('awaiting_reject'):
            try:
                update_order(int(text), 'rejected')
                await update.message.reply_text(f"❌ رد شد.", reply_markup=InlineKeyboardButton("🔙 بازگشت", callback_data='admin_back'))
            except:
                await update.message.reply_text("❌ نامعتبر.")
            context.user_data['awaiting_reject'] = False
        return

    if text == ADMIN_PASSWORD and not ADMIN_USER_ID:
        ADMIN_USER_ID = user_id
        await update.message.reply_text("🔐 ورود موفق!")
        await show_admin_panel(update, context)
    elif context.user_data.get('awaiting_tracking'):
        order = get_order_by_tracking(text)
        await update.message.reply_text(f"📊 وضعیت: {order[5] if order else 'نامعتبر'}")
        context.user_data['awaiting_tracking'] = False
    elif update.message.photo:
        await update.message.reply_text("📸 اسکرین‌شات دریافت شد. منتظر تایید ادمین باشید.")

async def show_admin_panel(update: Update, context: ContextTypes.DEFAULT_TYPE, query=None):
    keyboard = [
        [InlineKeyboardButton("📋 لیست سفارشات", callback_data='admin_list')],
        [InlineKeyboardButton("🔍 پیگیری", callback_data='admin_track')],
        [InlineKeyboardButton("🚫 بن", callback_data='admin_ban')],
        [InlineKeyboardButton("✅ انبن", callback_data='admin_unban')],
        [InlineKeyboardButton("✔️ تایید", callback_data='admin_approve')],
        [InlineKeyboardButton("❌ رد", callback_data='admin_reject')]
    ]
    reply_markup = InlineKeyboardMarkup(keyboard)
    message = query.message if query else update.message
    await message.reply_text("🎛️ پنل مدیریت", reply_markup=reply_markup)

def main():
    application = Application.builder().token(TOKEN).build()
    application.add_handler(CommandHandler("start", start))
    application.add_handler(CommandHandler("admin", admin_command))
    application.add_handler(CallbackQueryHandler(button_handler))
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, message_handler))
    application.add_handler(MessageHandler(filters.PHOTO, message_handler))
    application.run_polling()

if __name__ == '__main__':
    main()
