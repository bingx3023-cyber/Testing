import sqlite3
import random
from telegram import (
    Update,
    ReplyKeyboardMarkup,
    KeyboardButton,
    InlineKeyboardButton,
    InlineKeyboardMarkup
)
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    CallbackQueryHandler,
    ContextTypes,
    filters
)

BOT_TOKEN = "7656448308:AAEPkCNpBtiiw70r-pKuFPdWo6StZnBTeEE"
ADMIN_PASSWORD = "1390"
FORCE_CHANNEL = "@h4x_top"
CARD_NUMBER = "6037991234567890"
CARD_OWNER = "VPN SHOP"

conn = sqlite3.connect("data.db", check_same_thread=False)
c = conn.cursor()

c.execute('''
CREATE TABLE IF NOT EXISTS users(
    user_id INTEGER PRIMARY KEY,
    phone TEXT,
    invited_by INTEGER,
    referrals INTEGER DEFAULT 0,
    verified INTEGER DEFAULT 0,
    banned INTEGER DEFAULT 0
)
''')

c.execute('''
CREATE TABLE IF NOT EXISTS orders(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    volume TEXT,
    duration TEXT,
    amount TEXT,
    receipt TEXT,
    status TEXT DEFAULT 'pending'
)
''')

conn.commit()

steps = {}
admins = {}


async def is_joined(bot, user_id):
    try:
        member = await bot.get_chat_member(FORCE_CHANNEL, user_id)
        return member.status in ['member', 'administrator', 'creator']
    except:
        return False


async def check_access(update, context):
    user_id = update.effective_user.id

    c.execute("SELECT banned, verified FROM users WHERE user_id=?", (user_id,))
    user = c.fetchone()

    if user and user[0] == 1:
        await update.message.reply_text("شما بن شده‌اید")
        return False

    joined = await is_joined(context.bot, user_id)

    if not joined:
        kb = InlineKeyboardMarkup([
            [InlineKeyboardButton("عضویت در کانال", url="https://t.me/h4x_top")]
        ])

        await update.message.reply_text(
            "ابتدا عضو کانال شوید",
            reply_markup=kb
        )
        return False

    if not user:
        c.execute(
            "INSERT OR IGNORE INTO users(user_id, verified) VALUES(?,0)",
            (user_id,)
        )
        conn.commit()

    c.execute("SELECT verified FROM users WHERE user_id=?", (user_id,))
    verified = c.fetchone()[0]

    if verified == 0:
        kb = ReplyKeyboardMarkup(
            [[KeyboardButton("تایید شماره", request_contact=True)]],
            resize_keyboard=True
        )

        await update.message.reply_text(
            "برای ادامه شماره خود را تایید کنید",
            reply_markup=kb
        )
        return False

    return True


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    invited_by = None

    if context.args:
        try:
            invited_by = int(context.args[0])
        except:
            pass

    c.execute("SELECT * FROM users WHERE user_id=?", (user_id,))
    user = c.fetchone()

    if not user:
        c.execute(
            "INSERT INTO users(user_id, invited_by) VALUES(?,?)",
            (user_id, invited_by)
        )
        conn.commit()

    access = await check_access(update, context)

    if not access:
        return

    kb = ReplyKeyboardMarkup(
        [
            ["خرید سرویس"],
            ["تست رایگان"],
            ["لینک رفرال من"]
        ],
        resize_keyboard=True
    )

    await update.message.reply_text(
        "به فروشگاه VPN خوش آمدید",
        reply_markup=kb
    )


async def save_contact(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    phone = update.message.contact.phone_number

    c.execute(
        "UPDATE users SET phone=?, verified=1 WHERE user_id=?",
        (phone, user_id)
    )
    conn.commit()

    c.execute("SELECT invited_by FROM users WHERE user_id=?", (user_id,))
    inviter = c.fetchone()[0]

    if inviter:
        c.execute(
            "UPDATE users SET referrals = referrals + 1 WHERE user_id=?",
            (inviter,)
        )
        conn.commit()

    await update.message.reply_text("شماره تایید شد")


async def referral(update: Update, context: ContextTypes.DEFAULT_TYPE):
    access = await check_access(update, context)

    if not access:
        return

    user_id = update.effective_user.id

    bot = await context.bot.get_me()

    link = f"https://t.me/{bot.username}?start={user_id}"

    await update.message.reply_text(
        f"""
برای دریافت تست رایگان باید ۱۰ نفر واقعی دعوت کنید.

شرایط:
- عضویت در کانال
- تایید شماره

لینک شما:
{link}
"""
    )


async def free_test(update: Update, context: ContextTypes.DEFAULT_TYPE):
    access = await check_access(update, context)

    if not access:
        return

    user_id = update.effective_user.id

    c.execute("SELECT referrals FROM users WHERE user_id=?", (user_id,))
    refs = c.fetchone()[0]

    if refs < 10:
        await update.message.reply_text(
            f"شما {refs} رفرال دارید"
        )
        return

    await update.message.reply_text(
        "vmess://test-config"
    )


async def buy(update: Update, context: ContextTypes.DEFAULT_TYPE):
    access = await check_access(update, context)

    if not access:
        return

    steps[update.effective_user.id] = {
        "step": "volume"
    }

    await update.message.reply_text("حجم سرویس را وارد کنید")


async def text_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    text = update.message.text

    if user_id in admins:
        if text.startswith('/ban'):
            uid = int(text.split()[1])
            c.execute("UPDATE users SET banned=1 WHERE user_id=?", (uid,))
            conn.commit()
            await update.message.reply_text("بن شد")
            return

        if text.startswith('/unban'):
            uid = int(text.split()[1])
            c.execute("UPDATE users SET banned=0 WHERE user_id=?", (uid,))
            conn.commit()
            await update.message.reply_text("آن بن شد")
            return

        if text == '/orders':
            c.execute("SELECT * FROM orders WHERE status='pending'")
            orders = c.fetchall()

            for order in orders:
                kb = InlineKeyboardMarkup([
                    [
                        InlineKeyboardButton("تایید", callback_data=f"approve_{order[0]}"),
                        InlineKeyboardButton("رد", callback_data=f"reject_{order[0]}")
                    ]
                ])

                await context.bot.send_photo(
                    user_id,
                    order[5],
                    caption=f"ORDER #{order[0]}
USER: {order[1]}",
                    reply_markup=kb
                )
            return

    if user_id not in steps:
        return

    step = steps[user_id]['step']

    if step == 'volume':
        steps[user_id]['volume'] = text
        steps[user_id]['step'] = 'duration'

        await update.message.reply_text("مدت زمان را وارد کنید")

    elif step == 'duration':
        steps[user_id]['duration'] = text

        amount = f"150.{random.randint(1000,9999)}"

        steps[user_id]['amount'] = amount
        steps[user_id]['step'] = 'receipt'

        await update.message.reply_text(
            f"""
مبلغ زیر را دقیق واریز کنید:

{amount}

کارت:
{CARD_NUMBER}

سپس عکس فیش را ارسال کنید
"""
        )


async def photo_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    if user_id not in steps:
        return

    if steps[user_id]['step'] != 'receipt':
        return

    file_id = update.message.photo[-1].file_id

    c.execute(
        "INSERT INTO orders(user_id, volume, duration, amount, receipt) VALUES(?,?,?,?,?)",
        (
            user_id,
            steps[user_id]['volume'],
            steps[user_id]['duration'],
            steps[user_id]['amount'],
            file_id
        )
    )
    conn.commit()

    del steps[user_id]

    await update.message.reply_text(
        "فیش ثبت شد"
    )


async def admin(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id

    if not context.args:
        return

    if context.args[0] == ADMIN_PASSWORD:
        admins[user_id] = True
        await update.message.reply_text(
            "ادمین شدید
/orders"
        )


async def callback(update: Update, context: ContextTypes.DEFAULT_TYPE):
    query = update.callback_query

    await query.answer()

    admin_id = query.from_user.id

    if admin_id not in admins:
        return

    data = query.data

    if data.startswith('approve_'):
        oid = int(data.split('_')[1])

        c.execute("UPDATE orders SET status='approved' WHERE id=?", (oid,))
        conn.commit()

        c.execute("SELECT user_id FROM orders WHERE id=?", (oid,))
        uid = c.fetchone()[0]

        admins[f"send_{admin_id}"] = uid

        await query.message.reply_text(
            "کانفیگ یا پیام تایید را بفرست"
        )

    elif data.startswith('reject_'):
        oid = int(data.split('_')[1])

        c.execute("SELECT user_id FROM orders WHERE id=?", (oid,))
        uid = c.fetchone()[0]

        admins[f"send_{admin_id}"] = uid

        await query.message.reply_text(
            "پیام رد را ارسال کن"
        )


async def admin_send(update: Update, context: ContextTypes.DEFAULT_TYPE):
    admin_id = update.effective_user.id

    key = f"send_{admin_id}"

    if key not in admins:
        return

    uid = admins[key]

    await context.bot.send_message(uid, update.message.text)

    await update.message.reply_text("ارسال شد")

    del admins[key]


app = Application.builder().token(BOT_TOKEN).build()

app.add_handler(CommandHandler("start", start))
app.add_handler(CommandHandler("admin", admin))
app.add_handler(CallbackQueryHandler(callback))
app.add_handler(MessageHandler(filters.CONTACT, save_contact))
app.add_handler(MessageHandler(filters.PHOTO, photo_handler))
app.add_handler(MessageHandler(filters.Regex("^خرید سرویس$"), buy))
app.add_handler(MessageHandler(filters.Regex("^تست رایگان$"), free_test))
app.add_handler(MessageHandler(filters.Regex("^لینک رفرال من$"), referral))
app.add_handler(MessageHandler(filters.TEXT, admin_send))
app.add_handler(MessageHandler(filters.TEXT, text_handler))

print("BOT STARTED")
app.run_polling()
